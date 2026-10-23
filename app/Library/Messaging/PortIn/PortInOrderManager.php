<?php

namespace App\Library\Messaging\PortIn;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\E164Normalizer;
use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Follows a number porting INTO managed messaging and, only when the carrier says it has landed, attaches it to the
 * right Business. It can create a tracking record and consume provider status events; it has NO way to create,
 * submit or pay for a port order (those stay a human action with the carrier) and never purchases a number.
 *
 * Safety: every transition is idempotent by provider event id; a number already held (pending/active) by ANY Business,
 * or tracked by another live order, is refused; status can only move forward; nothing is attached until the carrier
 * reports `ported`.
 */
final class PortInOrderManager
{
    /** Provider status -> internal status. Unknown provider statuses are recorded but never promote an order. */
    private const MAP = [
        'draft' => 'draft', 'in-process' => 'in_process', 'submitted' => 'submitted', 'exception' => 'exception',
        'foc-date-confirmed' => 'foc_scheduled', 'ported' => 'ported', 'cancelled' => 'cancelled', 'canceled' => 'cancelled',
        'cancel-pending' => 'in_process',
    ];

    private const RANK = ['draft' => 0, 'submitted' => 1, 'in_process' => 2, 'exception' => 2, 'foc_scheduled' => 3, 'ported' => 4, 'assigned' => 5, 'cancelled' => 9];

    private const LIVE = ['draft', 'submitted', 'in_process', 'exception', 'foc_scheduled', 'ported'];

    public function __construct(private readonly BusinessMessagingIdentityResolver $identities)
    {
    }

    /** @return array<string, mixed> the order row */
    public function track(Business $business, string $phone, ?int $actorUserId = null, ?int $locationId = null, ?string $providerOrderId = null): array
    {
        $e164 = E164Normalizer::normalize($phone) ?? throw new RuntimeException('The number must be a valid international number.');

        if ($locationId !== null && ! DB::table('business_locations')->where('id', $locationId)->where('business_id', $business->id)->exists()) {
            throw new RuntimeException('That Location does not belong to this Business.');
        }

        return DB::transaction(function () use ($business, $e164, $actorUserId, $locationId, $providerOrderId): array {
            $held = DB::table('business_messaging_numbers')->where('phone_number', $e164)->whereIn('status', [BusinessMessagingNumberStatus::Pending->value, BusinessMessagingNumberStatus::Active->value])->lockForUpdate()->exists();
            if ($held) {
                throw new RuntimeException('That number is already held by a messaging account.');
            }
            if (DB::table('messaging_port_in_orders')->where('active_phone_number', $e164)->lockForUpdate()->exists()) {
                throw new RuntimeException('That number already has a live port order.');
            }

            $id = DB::table('messaging_port_in_orders')->insertGetId([
                'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'business_location_id' => $locationId,
                'requested_by_user_id' => $actorUserId, 'phone_number' => $e164, 'provider_order_id' => $providerOrderId,
                'status' => $providerOrderId === null ? 'draft' : 'submitted', 'active_phone_number' => $e164,
                'checklist' => json_encode(self::checklist()), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return (array) DB::table('messaging_port_in_orders')->find($id);
        });
    }

    /** Link a tracked order to the carrier's order id once a human has created it with the carrier. */
    public function linkProviderOrder(int $orderId, string $providerOrderId): void
    {
        DB::table('messaging_port_in_orders')->where('id', $orderId)->whereNull('provider_order_id')->update(['provider_order_id' => $providerOrderId, 'updated_at' => now()]);
    }

    /**
     * Apply one verified provider status event. Idempotent by $eventId.
     *
     * @return 'applied'|'duplicate'|'unknown_order'|'ignored'
     */
    public function applyProviderStatus(string $providerOrderId, string $providerStatus, string $eventId, ?Carbon $focAt = null, ?string $providerNumberId = null): string
    {
        return DB::transaction(function () use ($providerOrderId, $providerStatus, $eventId, $focAt, $providerNumberId): string {
            $order = DB::table('messaging_port_in_orders')->where('provider_order_id', $providerOrderId)->lockForUpdate()->first();

            if (! $this->recordEvent($eventId, $order?->id, $order === null ? 'unknown_order' : 'applied')) {
                return 'duplicate';
            }

            if ($order === null) {
                return 'unknown_order';
            }

            $target = self::MAP[strtolower($providerStatus)] ?? null;
            $update = ['provider_status' => $providerStatus, 'updated_at' => now()];

            // Forward-only: a late or replayed older event never moves an order backwards (cancelled is terminal).
            if ($target !== null && (self::RANK[$target] ?? 0) > (self::RANK[$order->status] ?? 0) && $order->status !== 'cancelled') {
                $update['status'] = $target;
            }
            if ($focAt !== null) {
                $update['foc_at'] = $focAt->clone()->utc();
            }
            if (($update['status'] ?? null) === 'ported') {
                $update['ported_at'] = now();
            }
            if (($update['status'] ?? null) === 'cancelled') {
                $update['active_phone_number'] = null;
            }

            DB::table('messaging_port_in_orders')->where('id', $order->id)->update($update);

            if (($update['status'] ?? $order->status) === 'ported') {
                $this->assignIfReady((int) $order->id, $providerNumberId);
            }

            return $target === null ? 'ignored' : 'applied';
        });
    }

    /**
     * After the carrier reports `ported`: attach the number to the ordering Business's messaging identity and (if the order named
     * one) its Location. Needs an identity with a real messaging profile; otherwise the order stays `ported` for a human to finish.
     */
    public function assignIfReady(int $orderId, ?string $providerNumberId = null): bool
    {
        $order = DB::table('messaging_port_in_orders')->where('id', $orderId)->lockForUpdate()->first();
        if ($order === null || $order->status !== 'ported') {
            return false;
        }

        $business = Business::query()->find($order->business_id);
        $identity = $business === null ? null : $this->identities->resolveForBusiness($business);

        if ($identity === null || str_starts_with((string) $identity->messaging_profile_id, 'reserved:') || $identity->messaging_profile_id === null) {
            return false;
        }

        $hasPrimary = DB::table('business_messaging_numbers')->where('business_messaging_identity_id', $identity->id)->where('is_primary', true)->whereIn('status', ['pending', 'active'])->exists();
        $number = $this->identities->attachNumber($identity, (string) $order->phone_number, ! $hasPrimary, BusinessMessagingNumberStatus::Active, $providerNumberId);

        if ($order->business_location_id !== null) {
            $this->identities->assignLocations($number, $business, [(int) $order->business_location_id]);
        }

        DB::table('messaging_port_in_orders')->where('id', $orderId)->update([
            'status' => 'assigned', 'assigned_number_id' => $number->id, 'active_phone_number' => null, 'updated_at' => now(),
        ]);

        return true;
    }

    private function recordEvent(string $eventId, ?int $orderId, string $outcome): bool
    {
        return DB::table('messaging_port_in_events')->insertOrIgnore([
            'provider' => 'telnyx', 'provider_event_id' => $eventId, 'port_in_order_id' => $orderId, 'outcome' => $outcome, 'received_at' => now(),
        ]) === 1;
    }

    /** @return list<array{step: string, owner: string, done: bool}> */
    public static function checklist(): array
    {
        return array_map(fn (array $s) => $s + ['done' => false], [
            ['step' => 'Telnyx portability check passes', 'owner' => 'MotionGrove'],
            ['step' => 'Losing-carrier account data received', 'owner' => 'Losing carrier / customer'],
            ['step' => 'Letter of Authorization signed', 'owner' => 'Authorised account holder'],
            ['step' => 'Invoice or bill obtained', 'owner' => 'Customer'],
            ['step' => 'A2P 10DLC registration approved', 'owner' => 'MotionGrove'],
            ['step' => 'Charges and submission approved', 'owner' => 'MotionGrove operator'],
            ['step' => 'FOC date confirmed', 'owner' => 'Carrier'],
            ['step' => 'Post-port call / SMS / STOP tests', 'owner' => 'MotionGrove + customer'],
        ]);
    }
}
