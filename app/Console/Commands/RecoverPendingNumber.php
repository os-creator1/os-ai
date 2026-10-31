<?php

namespace App\Console\Commands;

use App\Enums\Usage\UsageReservationStatus;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\ProvisioningIncidentRecorder;
use App\Library\Messaging\TelnyxProvisioningAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Reusable recovery for an already-purchased Telnyx toll-free number whose
 * local finalization never completed (provisioning incident stage
 * number_order_outcome_unknown). The target is not hard-coded: it is read from
 * the incident and then PROVEN against Telnyx and the original wallet charge.
 *
 * Everything else — the GET-only Telnyx verification, charges_breakdown price
 * proof, collision checks, locked single-transaction write, audited incident
 * resolution and idempotency — is inherited from RecoverTelnyxOrder, so the
 * original Business 3 recovery keeps its exact behaviour.
 *
 * It never places an order, creates a Messaging Profile, reserves, commits or
 * charges anything, and never refunds or credits automatically.
 *
 * Billing: the customer prepaid the first month at purchase (reservation =
 * one-time fee + full monthly rental, verified against the carrier's records),
 * so the number gets the normal purchase-anniversary renewal date at the
 * verified full monthly rate. If that cannot be left in a resolved state (for
 * example the anniversary has already passed, which would bill immediately),
 * nothing at all is written and the incident stays open.
 */
class RecoverPendingNumber extends RecoverTelnyxOrder
{
    protected $signature = 'messaging:recover-pending-number
        {--business= : Business id that owns the incident}
        {--incident= : Provisioning incident id (stage number_order_outcome_unknown)}
        {--dry-run : Verify and report only; writes nothing (default)}
        {--execute : Perform the reconciliation}';

    protected $description = 'Recover an already-purchased Telnyx toll-free number from a number_order_outcome_unknown incident (GET-only against Telnyx; never buys, charges or refunds).';

    /** Order items scanned when looking for the number's parent order (250 per page). */
    private const MAX_ORDER_ITEM_PAGES = 40;

    public function handle(BusinessMessagingIdentityResolver $identities, ProvisioningIncidentRecorder $incidents): int
    {
        try {
            if ($this->option('execute') && $this->option('dry-run')) {
                throw new \DomainException('Choose either --dry-run or --execute, not both.');
            }

            $businessId = (string) $this->option('business');
            $incidentId = (string) $this->option('incident');

            if (! ctype_digit($businessId) || ! ctype_digit($incidentId) || (int) $businessId < 1 || (int) $incidentId < 1) {
                throw new \DomainException('Both --business and --incident are required (positive ids).');
            }

            $this->target = $this->discoverTarget((int) $businessId, (int) $incidentId);

            $this->line(sprintf(
                'Target: Business #%d, incident #%d, number %s, Telnyx resource %s, profile %s, parent order %s, wallet reservation #%d.',
                $this->t('business_id'), $this->t('incident_id'), $this->t('phone_number'), $this->t('phone_resource_id'),
                $this->t('messaging_profile_id'), $this->t('order_id'), $this->t('reservation_id'),
            ));

            return $this->process($identities, $incidents, (bool) $this->option('execute'));
        } catch (\DomainException $e) {
            $this->error('Aborted, nothing written: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    protected function commandTag(): string
    {
        return 'messaging:recover-pending-number';
    }

    protected function renewalRequired(): bool
    {
        return true;
    }

    protected function renewalAt(Carbon $activatedAt): ?Carbon
    {
        return $activatedAt->copy()->addMonthNoOverflow();
    }

    protected function assertBillingResolved(array $facts): void
    {
        if (! $this->renewalAt($facts['purchased_at'])->isFuture()) {
            throw new \DomainException('Billing would be unresolved: the purchase anniversary has already passed, so the first renewal would be charged immediately. The number is NOT attached and the incident stays open for a billing decision.');
        }
    }

    protected function renewalSentence(?Carbon $renewalAt): string
    {
        return $renewalAt === null
            ? 'Renewal: none scheduled.'
            : 'Renewal: the first month was prepaid at purchase, so the first renewal is due ' . $renewalAt->toIso8601String() . ' at the verified full monthly rate (the normal purchase schedule).';
    }

    /**
     * Read the target from the incident and the local database only; Telnyx is
     * asked (GET) for the number resource and its parent order.
     *
     * @return array<string, int|string>
     */
    private function discoverTarget(int $businessId, int $incidentId): array
    {
        $incident = DB::table('business_messaging_provisioning_incidents')->where('id', $incidentId)->first();

        if ($incident === null || (int) $incident->business_id !== $businessId) {
            throw new \DomainException("Incident {$incidentId} does not exist for Business {$businessId}.");
        }

        if ($incident->stage !== TelnyxProvisioningAdapter::INCIDENT_NUMBER_ORDER_UNKNOWN) {
            throw new \DomainException("Incident {$incidentId} is not a " . TelnyxProvisioningAdapter::INCIDENT_NUMBER_ORDER_UNKNOWN . ' incident.');
        }

        $phone = (string) $incident->phone_number;
        $profile = (string) $incident->messaging_profile_id;

        if (preg_match('/^\+1[2-9]\d{9}$/', $phone) !== 1 || trim($profile) === '') {
            throw new \DomainException('The incident does not record a usable phone number and Messaging Profile.');
        }

        if ($incident->number_type !== 'toll_free') {
            throw new \DomainException('Only toll-free numbers can be recovered (their billing is verified from the carrier records).');
        }

        $base = ['business_id' => $businessId, 'incident_id' => $incidentId, 'phone_number' => $phone, 'messaging_profile_id' => $profile];

        // Already finished by this command: rebuild the target from its own audit
        // note and local rows so the idempotent end-state check can confirm it.
        if ($incident->resolved_at !== null) {
            return $base + $this->targetFromRecoveryNote($incident);
        }

        $reservation = $this->findReservation($businessId, $incident);
        $resourceId = $this->findNumberResource($phone);
        $orderId = $this->findParentOrder($phone);

        return $base + [
            'order_id' => $orderId,
            'phone_resource_id' => $resourceId,
            'reservation_id' => (int) $reservation->id,
            'reservation_amount_micro' => (int) $reservation->final_amount_micro,
        ];
    }

    /** @return array<string, int|string> */
    private function targetFromRecoveryNote(object $incident): array
    {
        $note = (string) $incident->resolution_note;

        if (! str_contains($note, '[recovered by ' . $this->commandTag() . ']')
            || preg_match('/Telnyx order (\S+) verified/', $note, $order) !== 1
            || preg_match('/resource (\d+) active/', $note, $resource) !== 1
            || preg_match('/reservation #(\d+) \(\$/', $note, $reservation) !== 1) {
            throw new \DomainException('Incident ' . $incident->id . ' is already resolved, but not by this command; refusing to touch it.');
        }

        $amount = (int) DB::table('business_usage_reservations')->where('id', (int) $reservation[1])->value('final_amount_micro');

        return [
            'order_id' => $order[1],
            'phone_resource_id' => $resource[1],
            'reservation_id' => (int) $reservation[1],
            'reservation_amount_micro' => $amount,
        ];
    }

    /**
     * The original number-purchase charge: the Business's committed
     * messaging_number_purchase reservation made around the incident. Exactly
     * one must qualify; the price is then proven against the carrier's records.
     */
    private function findReservation(int $businessId, object $incident): object
    {
        $at = Carbon::parse($incident->created_at);

        $candidates = DB::table('business_usage_reservations')
            ->where('business_id', $businessId)
            ->where('meter_key', TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE)
            ->where('status', UsageReservationStatus::Committed->value)
            ->whereBetween('committed_at', [$at->copy()->subDay(), $at->copy()->addDay()])
            ->orderBy('id')
            ->get();

        if ($candidates->count() !== 1) {
            throw new \DomainException(sprintf(
                'Expected exactly one committed number-purchase reservation near the incident; found %d%s.',
                $candidates->count(),
                $candidates->isEmpty() ? '' : ' (#' . $candidates->pluck('id')->implode(', #') . ')',
            ));
        }

        return $candidates->first();
    }

    /** @return array<string, mixed> */
    private function telnyxGet(string $path, array $query = []): array
    {
        $apiKey = (string) (config('services.telnyx.api_key') ?? '');

        if ($apiKey === '') {
            throw new \DomainException('services.telnyx.api_key is not configured.');
        }

        try {
            $response = Http::withToken($apiKey)->timeout(20)->acceptJson()->get(self::API_BASE . $path, $query);
        } catch (\Throwable) {
            throw new \DomainException("Telnyx GET {$path} did not complete.");
        }

        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new \DomainException("Telnyx GET {$path} returned HTTP {$response->status()}.");
        }

        return $response->json();
    }

    /** The Telnyx phone-number RESOURCE id (not an order-item id). */
    private function findNumberResource(string $phone): string
    {
        $found = $this->telnyxGet('/phone_numbers', ['filter[phone_number]' => substr($phone, 2), 'page[size]' => 20]);
        $matches = array_values(array_filter($found['data'], fn ($row): bool => is_array($row) && ($row['phone_number'] ?? null) === $phone));

        if (count($matches) !== 1 || ! is_scalar($matches[0]['id'] ?? null) || trim((string) $matches[0]['id']) === '') {
            throw new \DomainException(sprintf('Expected exactly one Telnyx number resource for %s; found %d.', $phone, count($matches)));
        }

        return trim((string) $matches[0]['id']);
    }

    /**
     * The PARENT order id. Telnyx's order-item id is not the order id; the item
     * names its parent in order_request_id. Exactly one successful item must
     * carry this number.
     */
    private function findParentOrder(string $phone): string
    {
        $matches = [];

        for ($page = 1; $page <= self::MAX_ORDER_ITEM_PAGES; $page++) {
            $response = $this->telnyxGet('/number_order_phone_numbers', ['filter[country_code]' => 'US', 'page[number]' => $page, 'page[size]' => 250]);
            $rows = array_values(array_filter($response['data'], 'is_array'));

            foreach ($rows as $row) {
                if (($row['phone_number'] ?? null) === $phone) {
                    $matches[] = $row;
                }
            }

            if (count($rows) < 250) {
                break;
            }

            if ($page === self::MAX_ORDER_ITEM_PAGES) {
                throw new \DomainException('Too many Telnyx order items to scan for the parent order; refusing to guess.');
            }
        }

        if (count($matches) !== 1
            || strtolower((string) ($matches[0]['status'] ?? '')) !== 'success'
            || ! is_scalar($matches[0]['order_request_id'] ?? null)
            || trim((string) $matches[0]['order_request_id']) === '') {
            throw new \DomainException(sprintf('Expected exactly one successful Telnyx order item for %s naming its parent order; found %d.', $phone, count($matches)));
        }

        return trim((string) $matches[0]['order_request_id']);
    }
}
