<?php

namespace App\Library\Messaging;

use App\Enums\Usage\UsageLedgerEntryType;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Settles every managed message to what it should cost:
 *
 *   expected = approved retail base x Telnyx's BILLABLE PARTS + Telnyx's ACTUAL carrier surcharge
 *
 * It never replaces the retail base with Telnyx's cost (that would erase the
 * approved price) and never adds the provider's full cost on top of it: only
 * the actual surcharge is added. Telnyx's message record
 * (GET /v2/messages/{id}) is only trusted once `completed_at` is set and is
 * read as: `parts` = billable parts; `cost_breakdown.carrier_fee.amount` =
 * the surcharge for the message; `cost_breakdown.rate.amount` = Telnyx's own
 * base rate; `cost.amount` = their total. The surcharge is the carrier_fee,
 * or — only when it is absent — `cost - rate` (documented derivation). If
 * those figures contradict each other, or neither is available, the
 * message goes to a visible INCIDENT instead of a guess.
 *
 * Run automatically and safely: every settlement is idempotent (stable
 * per-message, per-revision keys), retries are bounded, small variances are
 * applied on their own, and anything larger than the configured limit, or
 * unreadable, becomes an incident for an administrator. A later Telnyx
 * billing adjustment is settled by its own delta via $recheckDays.
 * BYO Telnyx traffic is never in the operations table as managed, so it is
 * never reconciled or charged here.
 */
final class MessageCostReconciler
{
    private const API = 'https://api.telnyx.com/v2/messages/';

    public function __construct(
        private readonly ChargeCarrierFee $carrierFee,
        private readonly UsageWalletManager $wallet,
        private readonly UsageMeterRepository $meters,
    ) {
    }

    /**
     * @return array{examined: int, not_final: int, matched: int, settled: int, open: int, incidents: int, variance_micro: int}
     */
    public function run(int $days, bool $apply, ?int $actorUserId = null, int $limit = 500, int $recheckDays = 0): array
    {
        $summary = ['examined' => 0, 'not_final' => 0, 'matched' => 0, 'settled' => 0, 'open' => 0, 'incidents' => 0, 'variance_micro' => 0];
        $actorUserId ??= ($configured = config('messaging.reconciliation.actor_user_id')) !== null && ctype_digit((string) $configured) ? (int) $configured : null;

        $this->seed($days, $limit);

        $rows = DB::table('messaging_cost_reconciliations')
            ->where(function ($q) use ($recheckDays): void {
                $q->where(function ($q): void {
                    $q->whereIn('status', ['pending', 'open'])
                        ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
                });

                if ($recheckDays > 0) {
                    $q->orWhere(fn ($q) => $q->whereIn('status', ['matched', 'settled'])->where('created_at', '>=', now()->subDays($recheckDays)));
                }
            })
            ->orderBy('id')->limit($limit)->get();

        foreach ($rows as $row) {
            $summary['examined']++;

            try {
                $this->process($row, $apply, $actorUserId, $summary);
            } catch (\Throwable $e) {
                $this->retry($row, 'processing_error', $e::class . ': ' . $e->getMessage(), $summary);
            }
        }

        $summary['open'] = DB::table('messaging_cost_reconciliations')->where('status', 'open')->count();
        $summary['incidents'] = DB::table('messaging_cost_reconciliations')->where('status', 'incident')->count();

        return $summary;
    }

    /** Registers every billable managed message not yet tracked. */
    private function seed(int $days, int $limit): void
    {
        $operations = DB::table(ManagedMessageDispatcher::TABLE)
            ->where('transport_mode', 'managed')
            ->where('provider', 'telnyx')
            ->whereNotNull('provider_message_id')
            ->where(function ($q): void {
                $q->where(fn ($q) => $q->where('direction', 'outbound')->whereIn('status', ['accepted', 'delivered', 'failed']))
                    ->orWhere(fn ($q) => $q->where('direction', 'inbound'));
            })
            ->where('occurred_at', '>=', now()->subDays($days))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('messaging_cost_reconciliations')->whereColumn('messaging_cost_reconciliations.operation_id', ManagedMessageDispatcher::TABLE . '.id'))
            ->orderBy('id')->limit($limit)->get();

        foreach ($operations as $op) {
            if ($op->direction === 'outbound' && $this->committedReservation(ManagedTransportBilling::reservationKey((int) $op->business_id, (string) $op->operation_key)) === null) {
                continue; // never billed (transport unmetered, or rejected): nothing to reconcile
            }

            DB::table('messaging_cost_reconciliations')->insertOrIgnore([
                'business_id' => (int) $op->business_id,
                'operation_id' => (int) $op->id,
                'direction' => (string) $op->direction,
                'provider_message_id' => (string) $op->provider_message_id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function process(object $row, bool $apply, ?int $actorUserId, array &$summary): void
    {
        $op = DB::table(ManagedMessageDispatcher::TABLE)->where('id', $row->operation_id)->first();
        $business = Business::query()->find($row->business_id);

        if ($op === null || $business === null) {
            $this->incident($row, 'operation_missing', 'The message operation or its Business no longer exists.', $summary);

            return;
        }

        $record = $this->fetch((string) $row->provider_message_id);

        if ($record === null) {
            $this->retry($row, 'fetch_failed', 'The Telnyx message record could not be read.', $summary);

            return;
        }

        if (($record['data']['completed_at'] ?? null) === null || ! isset($record['data']['cost']['amount'])) {
            $summary['not_final']++;
            $this->retry($row, 'cost_not_final', 'Telnyx has not finalised this message cost.', $summary);

            return;
        }

        try {
            $facts = $this->facts($record['data'], $op);
        } catch (ReconciliationIncident $e) {
            $this->incident($row, $e->incidentCode, $e->getMessage(), $summary);

            return;
        }

        $outbound = $op->direction === 'outbound';
        $numberType = $outbound ? null : DB::table('business_messaging_numbers')
            ->where('business_messaging_identity_id', $op->business_messaging_identity_id)->orderByDesc('is_primary')->value('number_type');
        $meterKey = MessageMeters::for((string) $op->message_type, (string) $op->direction, $numberType);
        $parts = $facts['billable_parts'];

        // The retail base per part and what has been billed so far.
        if ($outbound) {
            $reservation = $this->committedReservation(ManagedTransportBilling::reservationKey($business, (string) $op->operation_key));
            $surchargeReservation = $this->committedReservation(ManagedTransportBilling::surchargeKey($business, (string) $op->operation_key));

            if ($reservation === null) {
                $this->incident($row, 'billing_missing', 'No committed charge exists for this accepted message.', $summary);

                return;
            }

            $rate = (int) $reservation->retail_rate_micro;
            $billed = (int) $reservation->final_amount_micro + (int) ($surchargeReservation->final_amount_micro ?? 0);
        } else {
            $active = $this->meters->findByMeterKey($meterKey);
            $rateRow = $active?->active_rate_id !== null && $active->is_metered ? DB::table('business_usage_rates')->where('id', $active->active_rate_id)->first() : null;

            if ($rateRow === null) {
                $this->incident($row, 'meter_unpriced', "The {$meterKey} meter has no active rate, so this inbound message cannot be billed.", $summary);

                return;
            }

            $rate = (int) $rateRow->retail_rate_micro;
            $billed = $this->chargeInboundBase($business, $row, $meterKey, $parts);

            if ($billed === null) {
                $this->retry($row, 'wallet_shortfall', 'The wallet could not cover the inbound message base charge.', $summary);

                return;
            }
        }

        $base = (int) bcmul((string) $rate, (string) $parts, 0);
        $expected = $base + $facts['surcharge_micro'];
        $delta = $expected - ($billed + (int) $row->settled_micro);

        DB::table('messaging_cost_reconciliations')->where('id', $row->id)->update([
            'meter_key' => $meterKey,
            'billable_parts' => $parts,
            'billed_micro' => $billed,
            'carrier_cost_micro' => $facts['cost_micro'],
            'surcharge_micro' => $facts['surcharge_micro'],
            'expected_micro' => $expected,
            'adjustment_micro' => $delta,
            'updated_at' => now(),
        ]);

        if ($delta === 0) {
            $this->finish($row, (int) $row->settled_micro !== 0 ? 'settled' : 'matched');
            $summary['matched']++;

            return;
        }

        $summary['variance_micro'] += $delta;

        if (abs($delta) > (int) config('messaging.reconciliation.max_auto_adjustment_micro')) {
            $this->incident($row, 'variance_exceeds_auto_limit', "Settlement of {$delta} micro-USD exceeds the automatic limit; an administrator must review it.", $summary);

            return;
        }

        if (! $apply) {
            DB::table('messaging_cost_reconciliations')->where('id', $row->id)->update(['status' => 'open']);

            return;
        }

        $revision = (int) $row->revision + 1;

        if ($delta > 0) {
            $outcome = $this->carrierFee->charge($business, $delta, sprintf('msg-recon-%d-r%d', $row->operation_id, $revision), MessageMeters::SURCHARGE);

            if (in_array($outcome['status'], ['charged', 'already_charged'], true)) {
                $this->applied($row, $delta, $revision);
                $summary['settled']++;
            } elseif ($outcome['status'] === 'not_configured') {
                $this->incident($row, 'surcharge_meter_unpriced', 'The carrier surcharge meter has no active rate, so the settlement cannot be billed.', $summary);
            } else {
                $this->retry($row, 'wallet_shortfall', 'The wallet could not cover the settlement: ' . ($outcome['reason'] ?? 'refused'), $summary);
            }

            return;
        }

        if ($actorUserId === null) {
            $this->incident($row, 'credit_actor_missing', 'An over-charge needs a credit but MESSAGING_RECONCILIATION_ACTOR_USER_ID is not set.', $summary);

            return;
        }

        $this->wallet->issueManualCredit(
            $business,
            UsageLedgerEntryType::ManualCredit,
            abs($delta),
            $actorUserId,
            'Message cost reconciliation: message ' . $row->provider_message_id . ' billed above its final retail charge.',
            self::deterministicUuid(sprintf('msg-recon-credit-%d-r%d', $row->operation_id, $revision)),
        );
        $this->applied($row, $delta, $revision);
        $summary['settled']++;
    }

    /**
     * Reads the facts the settlement needs from a Telnyx message record.
     *
     * @return array{billable_parts: int, cost_micro: int, surcharge_micro: int}
     *
     * @throws ReconciliationIncident
     */
    private function facts(array $data, object $op): array
    {
        $cost = $data['cost'] ?? null;

        if (! is_array($cost) || ($cost['currency'] ?? null) !== 'USD' || ! $this->isAmount($cost['amount'] ?? null)) {
            throw new ReconciliationIncident('cost_unreadable', 'The message cost is missing, not USD or not a decimal amount.');
        }

        $type = strtolower((string) ($data['type'] ?? ''));
        $direction = strtolower((string) ($data['direction'] ?? ''));

        if (($type !== '' && $type !== $op->message_type) || ($direction !== '' && $direction !== $op->direction)) {
            throw new ReconciliationIncident('record_mismatch', "Telnyx reports a {$direction} {$type} but the operation is a {$op->direction} {$op->message_type}.");
        }

        $parts = $data['parts'] ?? null;

        if (! is_int($parts) && ! (is_string($parts) && ctype_digit($parts))) {
            throw new ReconciliationIncident('parts_missing', 'Telnyx did not report the billable parts for this message.');
        }

        $parts = (int) $parts;
        $costMicro = $this->micro($cost['amount']);
        $breakdown = is_array($data['cost_breakdown'] ?? null) ? $data['cost_breakdown'] : [];
        $fee = is_array($breakdown['carrier_fee'] ?? null) ? ($breakdown['carrier_fee']['amount'] ?? null) : null;
        $rate = is_array($breakdown['rate'] ?? null) ? ($breakdown['rate']['amount'] ?? null) : null;

        if ($fee !== null && ! $this->isAmount($fee) || $rate !== null && ! $this->isAmount($rate)) {
            throw new ReconciliationIncident('breakdown_unreadable', 'The cost breakdown contains a non-decimal amount.');
        }

        if ($fee !== null && $rate !== null && abs($costMicro - ($this->micro($fee) + $this->micro($rate))) > 10) {
            throw new ReconciliationIncident('breakdown_mismatch', 'The total cost does not equal rate + carrier fee.');
        }

        if ($fee !== null) {
            $surcharge = $this->micro($fee);
        } elseif ($rate !== null) {
            $surcharge = $costMicro - $this->micro($rate); // documented derivation: total minus Telnyx's own base rate
        } elseif ($costMicro === 0) {
            $surcharge = 0;
        } else {
            throw new ReconciliationIncident('surcharge_unavailable', 'Telnyx reported only a total cost, so the carrier surcharge cannot be separated from it.');
        }

        if ($surcharge < 0) {
            throw new ReconciliationIncident('surcharge_negative', 'The derived carrier surcharge is negative.');
        }

        // A message Telnyx did not charge for (e.g. never delivered, cost 0) is not billed either.
        $billable = $costMicro > 0 ? max(1, $parts) : 0;

        return ['billable_parts' => $billable, 'cost_micro' => $costMicro, 'surcharge_micro' => $costMicro > 0 ? $surcharge : 0];
    }

    /** Charges (once) the retail base of an inbound message; returns what that base charge amounts to, or null when the wallet refused. */
    private function chargeInboundBase(Business $business, object $row, string $meterKey, int $parts): ?int
    {
        if ($parts === 0) {
            return 0;
        }

        $key = sprintf('msg_in:%d:%d', $business->id, $row->operation_id);
        $result = $this->wallet->reserve($business, $meterKey, $key, (string) $parts);

        if (! $result->granted || $result->reservationId === null) {
            return null;
        }

        $this->wallet->commit($result->reservationId, (string) $parts);

        return (int) ($this->committedReservation($key)->final_amount_micro ?? 0);
    }

    private function committedReservation(string $key): ?object
    {
        return DB::table('business_usage_reservations')->where('idempotency_key', $key)->where('status', 'committed')->first();
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $providerMessageId): ?array
    {
        try {
            $response = Http::withToken((string) config('services.telnyx.api_key'))->timeout(20)->acceptJson()->get(self::API . $providerMessageId);
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() && is_array($response->json('data')) ? $response->json() : null;
    }

    private function isAmount(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{1,9}(\.\d{1,8})?$/', $value) === 1;
    }

    private function micro(string $amount): int
    {
        return (int) bcmul(bcadd($amount, '0', 6), '1000000', 0);
    }

    private function applied(object $row, int $delta, int $revision): void
    {
        DB::table('messaging_cost_reconciliations')->where('id', $row->id)->update([
            'settled_micro' => (int) $row->settled_micro + $delta,
            'revision' => $revision,
            'status' => 'settled',
            'settled_at' => now(),
            'incident_code' => null,
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    private function finish(object $row, string $status): void
    {
        DB::table('messaging_cost_reconciliations')->where('id', $row->id)->update(['status' => $status, 'incident_code' => null, 'last_error' => null, 'updated_at' => now()]);
    }

    /** A bounded retry: backs off, and becomes a visible incident once the attempts are spent. */
    private function retry(object $row, string $code, string $message, array &$summary): void
    {
        $attempts = (int) $row->attempts + 1;

        if ($attempts >= max(1, (int) config('messaging.reconciliation.max_attempts'))) {
            $this->incident($row, $code, $message . " (gave up after {$attempts} attempts)", $summary, $attempts);

            return;
        }

        DB::table('messaging_cost_reconciliations')->where('id', $row->id)->update([
            'status' => in_array($row->status, ['matched', 'settled'], true) ? $row->status : 'pending',
            'attempts' => $attempts,
            'next_attempt_at' => now()->addMinutes(max(1, (int) config('messaging.reconciliation.retry_minutes')) * $attempts),
            'last_error' => mb_substr($code . ': ' . $message, 0, 1000),
            'updated_at' => now(),
        ]);
    }

    private function incident(object $row, string $code, string $message, array &$summary, ?int $attempts = null): void
    {
        DB::table('messaging_cost_reconciliations')->where('id', $row->id)->update([
            'status' => 'incident',
            'incident_code' => $code,
            'last_error' => mb_substr($message, 0, 1000),
            'attempts' => $attempts ?? (int) $row->attempts,
            'updated_at' => now(),
        ]);

        Log::error('Messaging cost reconciliation incident', ['reconciliation_id' => $row->id, 'business_id' => $row->business_id, 'operation_id' => $row->operation_id, 'code' => $code, 'message' => $message]);
    }

    /** A stable UUID for an idempotent credit (the same message revision always maps to the same operation id). */
    private static function deterministicUuid(string $seed): string
    {
        $h = md5($seed);

        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
