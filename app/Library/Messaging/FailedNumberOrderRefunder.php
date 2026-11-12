<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Enums\Usage\UsageReservationStatus;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

/**
 * Returns the original purchase charge to the wallet for a number order the carrier has DEFINITIVELY failed, and only
 * then. It never creates a new charge and never touches the carrier except to read.
 *
 * Every condition must hold, otherwise nothing is credited and the case is escalated to an operator:
 *  - automatic refunds are enabled and a platform administrator is configured as the actor on the credit;
 *  - the caller already saw the carrier's failure on two separate attempts (this class does not decide "failed");
 *  - the Business has no identity or number rows for it locally (the purchase was never attached);
 *  - Telnyx lists NO number resource for that phone on this account;
 *  - exactly one committed number-purchase reservation exists around the incident, within the refund cap.
 *
 * Idempotent: the credit goes through UsageWalletManager::issueManualCredit() under an operation id derived from the
 * incident, so any retry (or a crash between credit and incident resolution) replays the same single ledger entry. The
 * incident is resolved last, which also unblocks the customer to choose another number.
 */
final class FailedNumberOrderRefunder
{
    public const REFUNDED = 'refunded';
    public const NOT_SAFE = 'not_safe';

    private const API_BASE = 'https://api.telnyx.com/v2';

    public function __construct(
        private readonly UsageWalletManager $wallet,
        private readonly ProvisioningIncidentRecorder $incidents,
    ) {
    }

    /** @return array{status: string, detail: string} */
    public function refund(object $incident): array
    {
        $actor = (int) config('messaging.reconciliation.actor_user_id');

        if (! (bool) config('messaging.order_reconciliation.auto_refund_failed_orders') || $actor < 1) {
            return $this->notSafe('automatic refund is not configured (no platform administrator actor)');
        }

        $business = Business::query()->find((int) $incident->business_id);
        $phone = (string) $incident->phone_number;

        if ($business === null || preg_match('/^\+1[2-9]\d{9}$/', $phone) !== 1) {
            return $this->notSafe('the incident does not name a usable Business and phone number');
        }

        if (DB::table('business_messaging_identities')->where('business_id', $business->id)->whereIn('status', ['pending', 'active'])->exists()
            || DB::table('business_messaging_numbers')->where('phone_number', $phone)
                ->whereIn('status', [BusinessMessagingNumberStatus::Pending->value, BusinessMessagingNumberStatus::Active->value])->exists()) {
            return $this->notSafe('the Business or number already has local messaging rows; the order may have been attached');
        }

        $existsAtCarrier = $this->numberExistsAtCarrier($phone);
        if ($existsAtCarrier !== false) {
            return $this->notSafe($existsAtCarrier === null ? 'the carrier could not be asked whether the number exists' : 'the number exists at the carrier, so the order did not fail');
        }

        $reservation = $this->soleCommittedReservation($business, $incident);
        if ($reservation === null) {
            return $this->notSafe('there is not exactly one committed number-purchase reservation near the incident');
        }

        $amount = (int) $reservation->final_amount_micro;
        if ($amount < 1 || $amount > (int) config('messaging.order_reconciliation.max_auto_refund_micro')) {
            return $this->notSafe('the charge is zero or above the automatic refund limit');
        }

        $operationId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'motiongrove:number-order-refund:incident:' . $incident->id)->toString();
        $reason = sprintf('Number order for incident #%d failed at the carrier; purchase charge (reservation #%d) returned.', $incident->id, $reservation->id);

        $entry = $this->wallet->issueManualCredit($business, UsageLedgerEntryType::ManualCredit, $amount, $actor, $reason, $operationId);

        $resolved = $this->incidents->resolve((int) $incident->id, null, sprintf(
            '[auto-refunded] Telnyx reported the order for %s failed on two checks and lists no such number on the account; the committed charge of reservation #%d (%d micro-USD) was credited back as ledger entry #%d (operation %s). No number was bought or attached. The customer can choose another number.',
            $phone, $reservation->id, $amount, $entry->id, $operationId,
        ));

        return ['status' => self::REFUNDED, 'detail' => $resolved === 1 ? 'credited and resolved' : 'credited (already resolved)'];
    }

    /** @return bool|null true = a listed number resource exists, false = none, null = unknown */
    private function numberExistsAtCarrier(string $phone): ?bool
    {
        try {
            $response = Http::withToken((string) config('services.telnyx.api_key'))->timeout(20)->acceptJson()
                ->get(self::API_BASE . '/phone_numbers', ['filter[phone_number]' => substr($phone, 2), 'page[size]' => 20]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful() || ! is_array($response->json('data'))) {
            return null;
        }

        foreach ($response->json('data') as $row) {
            if (is_array($row) && ($row['phone_number'] ?? null) === $phone) {
                return true;
            }
        }

        return false;
    }

    private function soleCommittedReservation(Business $business, object $incident): ?object
    {
        $at = Carbon::parse($incident->created_at);

        $rows = DB::table('business_usage_reservations')
            ->where('business_id', $business->id)
            ->where('meter_key', TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE)
            ->where('status', UsageReservationStatus::Committed->value)
            ->whereBetween('committed_at', [$at->copy()->subDay(), $at->copy()->addDay()])
            ->orderBy('id')->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }

    /** @return array{status: string, detail: string} */
    private function notSafe(string $why): array
    {
        return ['status' => self::NOT_SAFE, 'detail' => $why];
    }
}
