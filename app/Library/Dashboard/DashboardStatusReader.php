<?php

namespace App\Library\Dashboard;

use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Customer Experience Slice 4 §5.2 / §7 — the wallet, website and Google
 * status of one or many Businesses in ONE statement, whatever the number of
 * Businesses: never one query per client (§7, §13 "No N+Business").
 *
 * It reads status columns and nothing else. No B5 table (reports, campaigns,
 * contacts, contact_groups, automation_executions) and no conversation table, so it
 * can never become a second KPI formula or a second Conversations owner
 * (§4.1, §9.2). It writes nothing.
 *
 * Every row it can return is keyed by a Business id the caller already holds
 * from the resolved CustomerContext; it never widens that set.
 */
final class DashboardStatusReader
{
    /**
     * GoogleLocationHealth cases that need the customer: the listing is
     * contested, switched off, duplicated or unverified. VerificationPending
     * and AwaitingReview are already in Google's hands, Unknown is no claim at
     * all, and Verified is healthy.
     */
    public const UNHEALTHY_GOOGLE_LOCATION_STATES = [
        GoogleLocationHealth::OwnershipConflict,
        GoogleLocationHealth::Suspended,
        GoogleLocationHealth::Disabled,
        GoogleLocationHealth::Duplicate,
        GoogleLocationHealth::Unverified,
    ];

    /**
     * @param  array<int, int>  $businessIds
     * @return array<int, BusinessStatusRow> keyed by Business id; every id asked for is present
     */
    public function forBusinesses(array $businessIds): array
    {
        $businessIds = array_values(array_unique(array_map('intval', $businessIds)));

        if ($businessIds === []) {
            return [];
        }

        $unhealthy = array_map(fn (GoogleLocationHealth $state) => $state->value, self::UNHEALTHY_GOOGLE_LOCATION_STATES);

        $rows = DB::table('businesses as b')
            ->leftJoin('business_usage_wallets as w', 'w.business_id', '=', 'b.id')
            ->leftJoin('currencies as c', 'c.id', '=', 'w.currency_id')
            ->leftJoin('websites as s', 's.business_id', '=', 'b.id')
            ->leftJoin('business_google_connections as g', 'g.business_id', '=', 'b.id')
            ->whereIn('b.id', $businessIds)
            ->select([
                'b.id as business_id',
                'w.id as wallet_id',
                'w.billing_status',
                'w.debt_balance_micro',
                'w.paid_activity_paused_at',
                'w.available_balance_micro',
                'w.auto_recharge_threshold_micro',
                'w.auto_recharge_enabled',
                'w.consecutive_recharge_failures',
                'w.committed_spend_this_period_micro',
                'w.spend_period_end_utc',
                'w.monthly_spend_cap_micro',
                'c.code as currency_code',
                's.status as website_status',
                'g.state as google_state',
            ])
            ->selectSub(
                DB::table('business_google_locations as l')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('l.business_id', 'b.id')
                    ->whereIn('l.verification_state', $unhealthy),
                'unhealthy_google_locations',
            )
            ->get();

        $result = [];

        foreach ($businessIds as $id) {
            $result[$id] = BusinessStatusRow::empty($id);
        }

        foreach ($rows as $row) {
            $id = (int) $row->business_id;
            $hasWallet = $row->wallet_id !== null;

            $result[$id] = new BusinessStatusRow(
                businessId: $id,
                hasWallet: $hasWallet,
                billingStatus: $row->billing_status !== null ? (string) $row->billing_status : null,
                debtBalanceMicro: $hasWallet ? (string) ($row->debt_balance_micro ?? '0') : '0',
                paidActivityPaused: $row->paid_activity_paused_at !== null,
                availableBalanceMicro: $hasWallet ? (string) ($row->available_balance_micro ?? '0') : '0',
                autoRechargeThresholdMicro: $row->auto_recharge_threshold_micro !== null ? (string) $row->auto_recharge_threshold_micro : null,
                autoRechargeEnabled: (bool) ($row->auto_recharge_enabled ?? false),
                consecutiveRechargeFailures: (int) ($row->consecutive_recharge_failures ?? 0),
                committedSpendThisPeriodMicro: $hasWallet ? (string) ($row->committed_spend_this_period_micro ?? '0') : '0',
                spendPeriodEndUtc: $row->spend_period_end_utc !== null ? CarbonImmutable::parse((string) $row->spend_period_end_utc, 'UTC') : null,
                monthlySpendCapMicro: $row->monthly_spend_cap_micro !== null ? (string) $row->monthly_spend_cap_micro : null,
                currencyCode: $row->currency_code !== null ? (string) $row->currency_code : null,
                websiteStatus: $row->website_status !== null ? (string) $row->website_status : null,
                googleConnectionState: $row->google_state !== null ? (string) $row->google_state : null,
                unhealthyGoogleLocations: (int) ($row->unhealthy_google_locations ?? 0),
            );
        }

        return $result;
    }

    public function forBusiness(int $businessId): BusinessStatusRow
    {
        return $this->forBusinesses([$businessId])[$businessId];
    }
}
