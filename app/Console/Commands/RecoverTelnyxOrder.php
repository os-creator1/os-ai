<?php

namespace App\Console\Commands;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\PhoneNumberType;
use App\Enums\Usage\UsageReservationStatus;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\ProvisioningIncidentRecorder;
use App\Library\Messaging\TelnyxProvisioningAdapter;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One-time, deliberately narrow recovery for ONE already-purchased Telnyx
 * number whose local finalization (identity + number rows) never completed:
 * Business #3, provisioning incident #3, carrier order 022c5e59-…, number
 * +18446980765.
 *
 * It only reconciles LOCAL rows with what Telnyx has already done. It never
 * places an order, creates a Messaging Profile, reserves, commits, releases
 * or charges anything: Telnyx is only ever read with GET, and wallet
 * reservation #21 (already committed, $2.10) is verified and left untouched.
 *
 * Default is --dry-run. --execute re-checks everything under row locks and
 * writes in one transaction; the incident is resolved only as the last step
 * of a fully successful reconciliation. A repeat run finds the finished state
 * and changes nothing. Any doubt — including pricing that cannot be verified
 * against the carrier AND the committed reservation — aborts with no writes.
 */
class RecoverTelnyxOrder extends Command
{
    private const API_BASE = 'https://api.telnyx.com/v2';

    protected $signature = 'messaging:recover-telnyx-order
        {--incident= : Provisioning incident id (must be 3)}
        {--order= : Telnyx number order id (must be the one recorded for incident 3)}
        {--dry-run : Verify and report only; writes nothing (default)}
        {--execute : Perform the reconciliation}';

    protected $description = 'One-time recovery of the already-purchased Telnyx number for Business 3 / incident 3 (GET-only against Telnyx; never buys or charges).';

    /**
     * The one recovery target (config/messaging.php, source-controlled — not
     * environment-driven).
     *
     * @return int|string
     */
    private function t(string $key): int|string
    {
        return config('messaging.telnyx_order_recovery.' . $key);
    }

    public function handle(BusinessMessagingIdentityResolver $identities, ProvisioningIncidentRecorder $incidents): int
    {
        try {
            if ($this->option('execute') && $this->option('dry-run')) {
                throw new \DomainException('Choose either --dry-run or --execute, not both.');
            }

            if ((string) $this->option('incident') !== (string) $this->t('incident_id') || (string) $this->option('order') !== $this->t('order_id')) {
                throw new \DomainException(sprintf('This command only recovers incident %d / order %s.', $this->t('incident_id'), $this->t('order_id')));
            }

            $execute = (bool) $this->option('execute');

            // Finished already? Then nothing — not even Telnyx — is touched.
            if ($this->alreadyReconciled()) {
                $this->info('Already reconciled: identity, number and resolved incident are in place. Nothing to do.');

                return self::SUCCESS;
            }

            $this->preflightLocal(false);
            $facts = $this->verifyAtTelnyx();

            $this->line(sprintf(
                'Verified at Telnyx: order success; %s active (resource %s) on profile %s; purchased %s; one-time %s + full monthly %s (number + SMS) verified against the billing records and reservation #%d.',
                $this->t('phone_number'), $this->t('phone_resource_id'), $this->t('messaging_profile_id'),
                $facts['purchased_at']->toIso8601String(),
                $this->usd($facts['upfront_micro']), $this->usd($facts['monthly_micro']), $this->t('reservation_id'),
            ));
            $this->warn(sprintf(
                'BILLING DECISION NEEDED (nothing is credited or altered by this command): the wallet was charged %s (reservation #%d) but the carrier has so far billed %s — a difference of %s. Credit, if any, belongs to UsageWalletManager::issueManualCredit() (platform admin, audited, idempotent) once the complete carrier charges and billing policy are confirmed.',
                $this->usd($facts['wallet_charged_micro'], 5), $this->t('reservation_id'),
                $this->usd($facts['carrier_charged_micro'], 5), $this->usd($facts['wallet_charged_micro'] - $facts['carrier_charged_micro'], 5),
            ));
            $this->line('Renewal: next_renewal_at will be left EMPTY, so no automatic renewal charge is scheduled until the billing decision is made.');

            if (! $execute) {
                $this->info('DRY RUN: no changes written. Re-run with --execute to reconcile.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($identities, $incidents, $facts): void {
                $this->preflightLocal(true);
                $this->write($identities, $incidents, $facts);
            });

            $this->info('Reconciled: identity + number created, incident resolved. No Telnyx writes, no new charge.');

            return self::SUCCESS;
        } catch (\DomainException $e) {
            $this->error('Aborted, nothing written: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * True only when the COMPLETE expected end state exists: the Business's
     * single active Telnyx identity on the expected profile, its one active
     * primary toll-free number carrying the resource id and costs, and this
     * command's own resolution of the exact incident. Anything in between is a
     * collision, never "already done".
     */
    private function alreadyReconciled(): bool
    {
        $numbers = BusinessMessagingNumber::query()->where('phone_number', $this->t('phone_number'))->get();

        if ($numbers->isEmpty()) {
            return false;
        }

        $fail = fn (string $why) => new \DomainException(sprintf('Number %s already exists locally but is not the complete expected end state (%s); refusing to touch it.', $this->t('phone_number'), $why));

        if ($numbers->count() !== 1) {
            throw $fail('several rows');
        }

        $number = $numbers->first();
        $identity = BusinessMessagingIdentity::query()->find($number->business_messaging_identity_id);

        if ($identity === null
            || (int) $identity->business_id !== $this->t('business_id')
            || $identity->provider !== MessagingProvider::Telnyx
            || $identity->status !== BusinessMessagingIdentityStatus::Active
            || $identity->messaging_profile_id !== $this->t('messaging_profile_id')) {
            throw $fail('identity');
        }

        if (BusinessMessagingIdentity::query()->where('business_id', $this->t('business_id'))->count() !== 1
            || BusinessMessagingNumber::query()->where('business_messaging_identity_id', $identity->id)->count() !== 1) {
            throw $fail('extra identities or numbers');
        }

        if ($number->provider_number_reference !== $this->t('phone_resource_id')
            || $number->status !== BusinessMessagingNumberStatus::Active
            || $number->number_type !== PhoneNumberType::TollFree
            || ! $number->is_primary
            || $number->activated_at === null
            || $number->upfront_cost_micro === null
            || $number->monthly_cost_micro === null
            || (int) $number->monthly_cost_micro < 1
            || $number->cost_currency !== 'USD') {
            throw $fail('number');
        }

        $incident = DB::table('business_messaging_provisioning_incidents')->where('id', $this->t('incident_id'))->first();

        if ($incident === null
            || (int) $incident->business_id !== $this->t('business_id')
            || $incident->stage !== TelnyxProvisioningAdapter::INCIDENT_NUMBER_ORDER_UNKNOWN
            || $incident->phone_number !== $this->t('phone_number')
            || $incident->messaging_profile_id !== $this->t('messaging_profile_id')
            || $incident->resolved_at === null
            || ! is_string($incident->resolution_note)
            || ! str_contains($incident->resolution_note, '[recovered by messaging:recover-telnyx-order]')
            || ! str_contains($incident->resolution_note, (string) $this->t('order_id'))
            || ! str_contains($incident->resolution_note, (string) $this->t('phone_resource_id'))) {
            throw $fail('incident');
        }

        return true;
    }

    /**
     * Local safety checks. With $lock true they run inside the write
     * transaction under row locks.
     */
    private function preflightLocal(bool $lock): void
    {
        $scope = static fn ($query) => $lock ? $query->lockForUpdate() : $query;

        $business = $scope(Business::query()->whereKey($this->t('business_id')))->first();

        if ($business === null) {
            throw new \DomainException('Business ' . $this->t('business_id') . ' does not exist.');
        }

        $incident = $scope(DB::table('business_messaging_provisioning_incidents')->where('id', $this->t('incident_id')))->first();

        if ($incident === null || (int) $incident->business_id !== $this->t('business_id')) {
            throw new \DomainException('Incident ' . $this->t('incident_id') . ' does not belong to Business ' . $this->t('business_id') . '.');
        }

        if ($incident->resolved_at !== null) {
            throw new \DomainException('Incident ' . $this->t('incident_id') . ' is already resolved but the local state is incomplete; investigate manually.');
        }

        if ($incident->stage !== TelnyxProvisioningAdapter::INCIDENT_NUMBER_ORDER_UNKNOWN) {
            throw new \DomainException('Incident ' . $this->t('incident_id') . ' is not a ' . TelnyxProvisioningAdapter::INCIDENT_NUMBER_ORDER_UNKNOWN . ' incident.');
        }

        foreach (['phone_number', 'messaging_profile_id'] as $column) {
            if ($incident->{$column} !== $this->t($column)) {
                throw new \DomainException("Incident {$column} does not exactly match the expected value.");
            }
        }

        $this->assertReservation($lock);

        if ($scope(BusinessMessagingIdentity::query()
            ->where('business_id', $this->t('business_id'))
            ->whereIn('status', [BusinessMessagingIdentityStatus::Pending->value, BusinessMessagingIdentityStatus::Active->value]))->exists()) {
            throw new \DomainException('Business ' . $this->t('business_id') . ' already has an active-or-pending messaging identity.');
        }

        if ($scope(BusinessMessagingIdentity::query()->where('messaging_profile_id', $this->t('messaging_profile_id')))->exists()) {
            throw new \DomainException('Messaging Profile ' . $this->t('messaging_profile_id') . ' is already attached to an identity.');
        }

        if ($scope(BusinessMessagingNumber::query()->where('phone_number', $this->t('phone_number')))->exists()) {
            throw new \DomainException('Number ' . $this->t('phone_number') . ' already exists locally.');
        }

        if ($scope(BusinessMessagingNumber::query()->where('provider_number_reference', $this->t('phone_resource_id')))->exists()) {
            throw new \DomainException('Telnyx resource ' . $this->t('phone_resource_id') . ' is already referenced by a local number.');
        }
    }

    /**
     * Reservation #21 must be the committed number-purchase charge of this
     * Business. It is only read, never altered.
     *
     * @return array{quantity_micro: int}
     */
    private function assertReservation(bool $lock): array
    {
        $query = DB::table('business_usage_reservations')->where('id', $this->t('reservation_id'));
        $reservation = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($reservation === null
            || (int) $reservation->business_id !== $this->t('business_id')
            // Reservations carry the METER's canonical feature: the number
            // purchase meter belongs to the messaging_transport feature.
            || $reservation->meter_key !== TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE
            || $reservation->feature_key !== PlatformFeature::MessagingTransport->value
            || $reservation->status !== UsageReservationStatus::Committed->value
            || (int) $reservation->final_amount_micro !== $this->t('reservation_amount_micro')) {
            throw new \DomainException('Wallet reservation #' . $this->t('reservation_id') . ' is not the committed $2.10 number purchase of Business ' . $this->t('business_id') . '.');
        }

        $quantity = $reservation->final_quantity ?? $reservation->estimated_quantity;

        if (! is_string($quantity) && ! is_numeric($quantity)) {
            throw new \DomainException('Reservation #' . $this->t('reservation_id') . ' records no carrier-cost quantity to verify pricing against.');
        }

        return ['quantity_micro' => (int) bcmul((string) $quantity, '1000000', 0)];
    }

    /**
     * GET-only verification of the order, the number resource, the profile
     * and the price. Returns the facts to persist.
     *
     * @return array{purchased_at: Carbon, upfront_micro: int, monthly_micro: int, carrier_charged_micro: int, wallet_charged_micro: int}
     */
    private function verifyAtTelnyx(): array
    {
        $apiKey = (string) (config('services.telnyx.api_key') ?? '');

        if ($apiKey === '') {
            throw new \DomainException('services.telnyx.api_key is not configured.');
        }

        $get = function (string $path) use ($apiKey): array {
            try {
                $response = Http::withToken($apiKey)->timeout(20)->acceptJson()->get(self::API_BASE . $path);
            } catch (\Throwable) {
                throw new \DomainException("Telnyx GET {$path} did not complete.");
            }

            if (! $response->successful() || ! is_array($response->json('data'))) {
                throw new \DomainException("Telnyx GET {$path} returned HTTP {$response->status()}.");
            }

            return $response->json('data');
        };

        $order = $get('/number_orders/' . $this->t('order_id'));

        if ((string) ($order['id'] ?? '') !== $this->t('order_id') || strtolower((string) ($order['status'] ?? '')) !== 'success') {
            throw new \DomainException('The Telnyx order is not the expected order in status success.');
        }

        $ordered = array_values(array_filter((array) ($order['phone_numbers'] ?? []), fn ($row): bool => is_array($row) && ($row['phone_number'] ?? null) === $this->t('phone_number')));

        if (count($ordered) !== 1 || strtolower((string) ($ordered[0]['status'] ?? '')) !== 'success') {
            throw new \DomainException('The order does not contain exactly one successful ' . $this->t('phone_number') . '.');
        }

        if (isset($order['messaging_profile_id']) && $order['messaging_profile_id'] !== $this->t('messaging_profile_id')) {
            throw new \DomainException('The order names a different Messaging Profile.');
        }

        $number = $get('/phone_numbers/' . $this->t('phone_resource_id'));

        if ((string) ($number['id'] ?? '') !== $this->t('phone_resource_id')
            || ($number['phone_number'] ?? null) !== $this->t('phone_number')
            || strtolower((string) ($number['status'] ?? '')) !== 'active'
            || ($number['messaging_profile_id'] ?? null) !== $this->t('messaging_profile_id')) {
            throw new \DomainException('The Telnyx number resource is not the expected active number on the expected Messaging Profile.');
        }

        if (isset($ordered[0]['id']) && (string) $ordered[0]['id'] === $this->t('phone_resource_id')) {
            throw new \DomainException('The order-phone id unexpectedly equals the resource id; refusing to guess which is which.');
        }

        // The order and the number must belong to the same Telnyx account
        // (both reads also had to succeed with this platform's own key).
        if (isset($order['user_id'], $number['user_id']) && $order['user_id'] !== $number['user_id']) {
            throw new \DomainException('The order and the number belong to different Telnyx accounts.');
        }

        $profile = $get('/messaging_profiles/' . $this->t('messaging_profile_id'));

        if ((string) ($profile['id'] ?? '') !== $this->t('messaging_profile_id')) {
            throw new \DomainException('The Messaging Profile could not be confirmed on this Telnyx account.');
        }

        $purchasedAt = $this->parseTime($number['purchased_at'] ?? null) ?? $this->parseTime($order['created_at'] ?? null);

        if ($purchasedAt === null) {
            throw new \DomainException('Telnyx reports no purchase time, so the original renewal timing cannot be derived.');
        }

        $billing = $this->verifyBilling($purchasedAt, $order['user_id'] ?? ($number['user_id'] ?? null));

        return [
            'purchased_at' => $purchasedAt,
            'upfront_micro' => $billing['upfront_micro'],
            'monthly_micro' => $billing['monthly_micro'],
            'carrier_charged_micro' => $billing['carrier_charged_micro'],
            'wallet_charged_micro' => $billing['wallet_charged_micro'],
        ];
    }

    /**
     * The order and number resources no longer expose cost_information for a
     * purchased number, so price is verified against Telnyx's own billing
     * records (GET /charges_breakdown, top-level `results` + `currency`) AND the
     * committed reservation. Every ambiguity fails closed.
     *
     * For the number: exactly one otc/numbers, one partial-mrc/numbers and one
     * partial-mrc/SMS debit, in USD, inside the purchase month, and nothing
     * else. The full monthly rental is not read from the carrier (it is only
     * billed prorated so far): it is the reservation's charge less the verified
     * one-time fee less the configured SMS add-on, and it is accepted ONLY if
     * both prorated carrier amounts equal that full rate prorated for the days
     * left in the purchase month (purchase day included).
     *
     * @return array{upfront_micro: int, monthly_micro: int, carrier_charged_micro: int, wallet_charged_micro: int}
     */
    private function verifyBilling(Carbon $purchasedAt, mixed $accountId): array
    {
        $apiKey = (string) config('services.telnyx.api_key');
        $monthStart = $purchasedAt->copy()->startOfMonth();
        $monthEnd = $purchasedAt->copy()->endOfMonth();

        try {
            $response = Http::withToken($apiKey)->timeout(20)->acceptJson()->get(self::API_BASE . '/charges_breakdown', [
                'start_date' => $monthStart->toDateString(),
                'end_date' => $monthEnd->toDateString(),
            ]);
        } catch (\Throwable) {
            throw new \DomainException('Telnyx GET /charges_breakdown did not complete.');
        }

        $results = $response->json('results');

        if (! $response->successful() || ! is_array($results) || $results === [] || $response->json('currency') !== 'USD') {
            throw new \DomainException('Pricing cannot be verified: /charges_breakdown returned no usable USD results.');
        }

        $phone = (string) $this->t('phone_number');
        $found = [];

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowPhone = $this->field($row, ['phone_number', 'number']);
            $rowResource = $this->field($row, ['phone_number_id', 'resource_id']);

            $mine = $rowPhone === $phone || ($rowResource !== null && $rowResource === (string) $this->t('phone_resource_id'));

            if (! $mine) {
                continue;
            }

            if (($rowPhone !== null && $rowPhone !== $phone) || ($rowResource !== null && $rowResource !== (string) $this->t('phone_resource_id'))) {
                throw new \DomainException('A billing record names inconsistent number identifiers.');
            }

            $category = strtolower((string) $this->field($row, ['category', 'charge_category', 'charge_type', 'type']));
            $service = strtolower((string) $this->field($row, ['service', 'product', 'product_category', 'service_type']));
            $units = $this->toUnits($row['amount'] ?? $row['cost'] ?? $row['total'] ?? null);

            if ($category === '' || $service === '' || $units === null) {
                throw new \DomainException('A billing record for the number is missing its category, service or amount.');
            }

            if ($units >= 0) {
                throw new \DomainException("Billing record {$category}/{$service} is not a debit; refusing to interpret it.");
            }

            $rowAccount = $this->field($row, ['user_id', 'account_id']);

            if ($rowAccount !== null && ($accountId === null || (string) $accountId !== $rowAccount)) {
                throw new \DomainException('A billing record belongs to a different (or unverifiable) Telnyx account.');
            }

            $rowDate = $this->parseTime($this->field($row, ['date', 'start_date', 'period_start', 'billing_period_start']));

            if ($this->field($row, ['date', 'start_date', 'period_start', 'billing_period_start']) !== null
                && ($rowDate === null || $rowDate->lt($monthStart) || $rowDate->gt($monthEnd))) {
                throw new \DomainException('A billing record falls outside the purchase month.');
            }

            $key = $category . '/' . $service;

            if (isset($found[$key])) {
                throw new \DomainException("More than one {$key} billing record for the number; ambiguous.");
            }

            $found[$key] = -$units;
        }

        $expectedKeys = ['otc/numbers', 'partial-mrc/numbers', 'partial-mrc/sms'];

        if (array_diff_key($found, array_flip($expectedKeys)) !== [] || array_diff($expectedKeys, array_keys($found)) !== []) {
            throw new \DomainException('Billing records for the number are missing or include unexpected charges: ' . implode(', ', array_keys($found)) . '.');
        }

        $reservation = $this->assertReservation(false);
        $upfront = $found['otc/numbers'] * 10;
        $smsMonthly = max(0, (int) config('messaging.number_sms_addon_monthly_cost_micro', 0));
        $numberMonthly = $reservation['quantity_micro'] - $upfront - $smsMonthly;
        $ceiling = (int) config('messaging.number_price_ceiling_micro', 25_000_000);

        if ($upfront < 1 || $smsMonthly < 1 || $numberMonthly < 1 || $upfront > $ceiling || $numberMonthly + $smsMonthly > $ceiling) {
            throw new \DomainException('Pricing mismatch: the reservation, one-time fee and SMS add-on do not yield a valid monthly rate.');
        }

        $daysInMonth = (int) $purchasedAt->daysInMonth;
        $remaining = $daysInMonth - (int) $purchasedAt->day + 1;

        if ($this->prorate($numberMonthly, $remaining, $daysInMonth) !== $found['partial-mrc/numbers']
            || $this->prorate($smsMonthly, $remaining, $daysInMonth) !== $found['partial-mrc/sms']) {
            throw new \DomainException(sprintf(
                'Pricing mismatch: prorated carrier charges (numbers %d, SMS %d) do not match a %s + %s monthly rate for %d of %d days.',
                $found['partial-mrc/numbers'], $found['partial-mrc/sms'], $this->usd($numberMonthly), $this->usd($smsMonthly), $remaining, $daysInMonth,
            ));
        }

        return [
            'upfront_micro' => $upfront,
            'monthly_micro' => $numberMonthly + $smsMonthly,
            'carrier_charged_micro' => ($found['otc/numbers'] + $found['partial-mrc/numbers'] + $found['partial-mrc/sms']) * 10,
            'wallet_charged_micro' => $reservation['quantity_micro'],
        ];
    }

    /** @param list<string> $keys */
    private function field(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    /** A carrier amount as signed integer units of 0.00001 (the carrier's 5-decimal precision), or null if ambiguous. */
    private function toUnits(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $value = sprintf('%.6F', $value);
        }

        if (! is_string($value) || preg_match('/^(-?)(\d{1,9}(?:\.\d{1,6})?)$/', trim($value), $m) !== 1) {
            return null;
        }

        $scaled = bcmul($m[2], '100000', 6);

        if (! str_ends_with($scaled, '.000000')) {
            return null;
        }

        return ($m[1] === '-' ? -1 : 1) * (int) bcmul($m[2], '100000', 0);
    }

    /** monthly micro x days/daysInMonth, rounded half-up to the carrier's 5-decimal units. */
    private function prorate(int $monthlyMicro, int $days, int $daysInMonth): int
    {
        $exact = bcdiv(bcmul((string) $monthlyMicro, (string) $days, 0), (string) ($daysInMonth * 10), 6);

        return (int) bcadd($exact, '0.5', 0);
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function usd(int $micro, int $decimals = 2): string
    {
        return '$' . number_format($micro / 1_000_000, $decimals);
    }

    /**
     * @param  array{purchased_at: Carbon, upfront_micro: int, monthly_micro: int, carrier_charged_micro: int, wallet_charged_micro: int}  $facts
     */
    private function write(BusinessMessagingIdentityResolver $identities, ProvisioningIncidentRecorder $incidents, array $facts): void
    {
        $business = Business::query()->findOrFail($this->t('business_id'));
        $tz = (string) config('app.timezone');
        $activatedAt = $facts['purchased_at']->copy()->setTimezone($tz);

        $identity = $identities->create($business, $this->t('messaging_profile_id'), null, MessagingProvider::Telnyx, BusinessMessagingIdentityStatus::Active);
        $identity->forceFill(['activated_at' => $activatedAt])->save();

        $number = $identities->attachNumber(
            $identity,
            $this->t('phone_number'),
            true,
            BusinessMessagingNumberStatus::Active,
            $this->t('phone_resource_id'),
            PhoneNumberType::TollFree,
        );

        // attachNumber() stamps "now"; the real activation and renewal clock
        // are the original purchase's. Costs mirror the normal purchase path.
        DB::table('business_messaging_numbers')->where('id', $number->id)->update([
            'activated_at' => $activatedAt,
            // Deliberately empty: Telnyx bills recurring charges from the 1st of
            // the month, and the prepaid-balance discrepancy is unresolved. An
            // empty value keeps the renewal sweep from charging anything.
            'next_renewal_at' => null,
            'upfront_cost_micro' => $facts['upfront_micro'],
            'monthly_cost_micro' => $facts['monthly_micro'],
            'cost_currency' => 'USD',
            'updated_at' => now(),
        ]);

        $check = BusinessMessagingNumber::query()->find($number->id);

        if ($check === null
            || $check->provider_number_reference !== $this->t('phone_resource_id')
            || (int) $check->monthly_cost_micro !== $facts['monthly_micro']
            || $check->status !== BusinessMessagingNumberStatus::Active) {
            throw new \DomainException('Post-write verification failed; rolled back.');
        }

        $note = sprintf(
            '[recovered by messaging:recover-telnyx-order] Telnyx order %s verified success (GET only); number %s resource %s active on profile %s; purchased %s; upfront %s + full monthly rental %s verified against Telnyx billing records and committed wallet reservation #%d (%s) — preserved, no new order/profile/reservation/charge. Wallet charged %s vs carrier billed so far %s: any credit is a separate, pending billing decision (not applied). next_renewal_at left empty pending that decision. Identity #%d and number #%d created.',
            $this->t('order_id'), $this->t('phone_number'), $this->t('phone_resource_id'), $this->t('messaging_profile_id'),
            $activatedAt->toIso8601String(), $this->usd($facts['upfront_micro']), $this->usd($facts['monthly_micro']),
            $this->t('reservation_id'), $this->usd($this->t('reservation_amount_micro')),
            $this->usd($facts['wallet_charged_micro'], 5), $this->usd($facts['carrier_charged_micro'], 5),
            $identity->id, $number->id,
        );

        // Last step: only a complete reconciliation resolves the incident.
        if ($incidents->resolve($this->t('incident_id'), null, $note) !== 1) {
            throw new \DomainException('Incident could not be resolved (changed concurrently); rolled back.');
        }

        Log::info('Telnyx order recovered', ['incident_id' => $this->t('incident_id'), 'business_id' => $this->t('business_id'), 'identity_id' => $identity->id, 'number_id' => $number->id]);
    }
}
