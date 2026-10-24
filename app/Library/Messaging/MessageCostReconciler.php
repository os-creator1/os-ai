<?php

namespace App\Library\Messaging;

use App\Enums\Usage\UsageLedgerEntryType;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Compares what was billed for each managed message with Telnyx's FINAL
 * cost for it (GET /v2/messages/{id}: `cost` — the carrier fee included —
 * is calculated asynchronously and is only trusted once `completed_at` is
 * set), and settles the difference at cost on the Business the message
 * belongs to.
 *
 * - Outbound messages are billed per segment up front at the configured
 *   rate; that charge is an ESTIMATE, never presented as the actual cost.
 *   Only outbound messages that were actually billed (a committed
 *   reservation) are reconciled.
 * - Inbound messages are not billed when received; their carrier cost is
 *   billed here, once, when it is known.
 * - Under-billing is settled through the explicit carrier pass-through
 *   charge (idempotent per message, its multiplier is the owner's markup —
 *   1.00 means none); over-billing is credited back through the audited
 *   manual-credit path. Without $apply nothing is charged or credited: rows
 *   are recorded as open for review.
 */
final class MessageCostReconciler
{
    private const API = 'https://api.telnyx.com/v2/messages/';

    public function __construct(
        private readonly ChargeCarrierFee $carrierFee,
        private readonly UsageWalletManager $wallet,
    ) {
    }

    /**
     * @return array{examined: int, not_final: int, matched: int, undercharged: int, overcharged: int, settled: int, open: int, variance_micro: int}
     */
    public function run(int $days, bool $apply, ?int $actorUserId = null, int $limit = 500): array
    {
        $summary = ['examined' => 0, 'not_final' => 0, 'matched' => 0, 'undercharged' => 0, 'overcharged' => 0, 'settled' => 0, 'open' => 0, 'variance_micro' => 0];

        $operations = DB::table(ManagedMessageDispatcher::TABLE)
            ->where('transport_mode', 'managed')
            ->where('provider', 'telnyx')
            ->whereNotNull('provider_message_id')
            ->whereIn('status', ['accepted', 'delivered'])
            ->where('occurred_at', '>=', now()->subDays($days))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('messaging_cost_reconciliations')->whereColumn('messaging_cost_reconciliations.operation_id', ManagedMessageDispatcher::TABLE . '.id'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($operations as $operation) {
            $summary['examined']++;

            $billed = $this->billedMicro($operation);

            if ($billed === null) {
                continue; // an outbound message that was never billed (transport unmetered) is not reconciled
            }

            $actual = $this->finalCostMicro((string) $operation->provider_message_id);

            if ($actual === null) {
                $summary['not_final']++;

                continue;
            }

            $adjustment = $actual - $billed;
            $summary['variance_micro'] += $adjustment;

            DB::table('messaging_cost_reconciliations')->insert([
                'business_id' => (int) $operation->business_id,
                'operation_id' => (int) $operation->id,
                'direction' => (string) $operation->direction,
                'provider_message_id' => (string) $operation->provider_message_id,
                'billed_micro' => $billed,
                'carrier_cost_micro' => $actual,
                'adjustment_micro' => $adjustment,
                'status' => $adjustment === 0 ? 'matched' : ($adjustment > 0 ? 'undercharge_open' : 'overcharge_open'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $adjustment === 0 ? $summary['matched']++ : ($adjustment > 0 ? $summary['undercharged']++ : $summary['overcharged']++);
        }

        if ($apply) {
            $summary['settled'] = $this->settleOpen($actorUserId);
        }

        $summary['open'] = DB::table('messaging_cost_reconciliations')->whereIn('status', ['undercharge_open', 'overcharge_open'])->count();

        return $summary;
    }

    /** What the Business was billed for this message; null when it was never billed and should not be reconciled. */
    private function billedMicro(object $operation): ?int
    {
        if ($operation->direction === 'inbound') {
            return 0; // inbound is not billed at receipt
        }

        $reservation = DB::table('business_usage_reservations')
            ->where('idempotency_key', 'msg_transport:' . (int) $operation->business_id . ':' . $operation->operation_key)
            ->where('status', 'committed')
            ->first();

        return $reservation?->final_amount_micro === null ? null : (int) $reservation->final_amount_micro;
    }

    /** Telnyx's final cost for the message in micro-USD, or null while it is not final / not USD / not readable. */
    private function finalCostMicro(string $providerMessageId): ?int
    {
        try {
            $response = Http::withToken((string) config('services.telnyx.api_key'))->timeout(20)->acceptJson()->get(self::API . $providerMessageId);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $cost = $response->json('data.cost');
        $amount = is_array($cost) ? ($cost['amount'] ?? null) : null;

        if (! is_array($cost) || ($cost['currency'] ?? null) !== 'USD' || $response->json('data.completed_at') === null
            || ! is_string($amount) || preg_match('/^\d{1,9}(\.\d{1,8})?$/', $amount) !== 1) {
            return null;
        }

        return (int) bcmul(bcadd($amount, '0', 6), '1000000', 0);
    }

    private function settleOpen(?int $actorUserId): int
    {
        $settled = 0;

        foreach (DB::table('messaging_cost_reconciliations')->whereIn('status', ['undercharge_open', 'overcharge_open'])->orderBy('id')->get() as $row) {
            $business = Business::query()->find($row->business_id);

            if ($business === null) {
                continue;
            }

            if ($row->adjustment_micro > 0) {
                $outcome = $this->carrierFee->charge($business, (int) $row->adjustment_micro, 'msg-recon-' . $row->operation_id);

                if (in_array($outcome['status'], ['charged', 'already_charged'], true)) {
                    $this->mark($row->id, 'undercharge_billed');
                    $settled++;
                }

                continue;
            }

            if ($actorUserId === null) {
                continue; // a credit is an audited administrator action
            }

            $this->wallet->issueManualCredit(
                $business,
                UsageLedgerEntryType::ManualCredit,
                abs((int) $row->adjustment_micro),
                $actorUserId,
                'Message cost reconciliation: billed more than the carrier\'s final cost for message ' . $row->provider_message_id . '.',
                self::deterministicUuid('msg-recon-credit-' . $row->operation_id),
            );
            $this->mark($row->id, 'overcharge_credited');
            $settled++;
        }

        return $settled;
    }

    private function mark(int $id, string $status): void
    {
        DB::table('messaging_cost_reconciliations')->where('id', $id)->update(['status' => $status, 'settled_at' => now(), 'updated_at' => now()]);
    }

    /** A stable UUID for an idempotent credit (the same message always maps to the same operation id). */
    private static function deterministicUuid(string $seed): string
    {
        $h = md5($seed);

        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
