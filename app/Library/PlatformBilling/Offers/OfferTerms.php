<?php

namespace App\Library\PlatformBilling\Offers;

use Carbon\CarbonImmutable;

/**
 * The exact commercial terms of a Private Offer, as they would apply to one
 * customer at one moment. A plain, serialisable value: what is shown on the
 * redemption page, what the customer accepts, and what is frozen on their
 * redemption are all this same structure.
 *
 * `hash()` covers the COMMERCIAL facts only (amounts, trial, periods, plan,
 * currency, cycle) and deliberately not the calendar dates, which merely
 * derive from "today". That is what lets a customer accept the terms they were
 * shown without a midnight rollover invalidating the form, while any change to
 * what they would actually PAY still does.
 */
final class OfferTerms
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function kind(): string
    {
        return (string) $this->data['kind'];
    }

    public function currencyCode(): string
    {
        return (string) $this->data['currency_code'];
    }

    public function trialDays(): ?int
    {
        return $this->data['trial_days'] === null ? null : (int) $this->data['trial_days'];
    }

    public function todayChargeMinor(): int
    {
        return (int) $this->data['today_charge_minor'];
    }

    public function standardPriceMinor(): int
    {
        return (int) $this->data['standard_price_minor'];
    }

    public function firstPaymentAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->data['first_payment_at']);
    }

    /** @return array<int, array<string, mixed>> */
    public function phases(): array
    {
        return (array) $this->data['phases'];
    }

    /** The phases that CHARGE money (everything but the free trial). */
    public function paidPhases(): array
    {
        return array_values(array_filter($this->phases(), static fn (array $phase): bool => $phase['kind'] !== 'trial'));
    }

    public function hasPromotionEnd(): bool
    {
        return count($this->paidPhases()) > 1;
    }

    /** @return array<string, mixed>|null */
    public function promotion(): ?array
    {
        return $this->data['promotion'] ?? null;
    }

    /** Distinct paid amounts that need a Stripe Price. @return array<int, int> */
    public function requiredAmountsMinor(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $phase): int => (int) $phase['price_minor'],
            $this->paidPhases(),
        )));
    }

    /** Fills each paid phase's `price_id` from an amount => price id map. @param array<int, string> $priceIdsByAmount */
    public function withPriceIds(array $priceIdsByAmount): self
    {
        $data = $this->data;

        foreach ($data['phases'] as $index => $phase) {
            if ($phase['kind'] !== 'trial') {
                $data['phases'][$index]['price_id'] = $priceIdsByAmount[(int) $phase['price_minor']] ?? null;
            }
        }

        return new self($data);
    }

    /** @param array<string, mixed> $extra */
    public function withExtra(array $extra): self
    {
        return new self([...$this->data, ...$extra]);
    }

    public function hash(): string
    {
        // A billing change starts at the subscription's NEXT RENEWAL, and that
        // date is part of what the customer agrees to: if the form was left open
        // across a renewal the boundary moves, the hash no longer matches and the
        // terms are shown again. (A new subscription's start is "now", so it is
        // deliberately not part of the hash.)
        $isChange = ($this->data['kind'] ?? null) === 'billing_change';

        $paid = array_map(static fn (array $phase) => [
            $phase['kind'], (int) $phase['price_minor'], $phase['periods'] ?? null,
            $isChange ? ($phase['starts_at'] ?? null) : null,
        ], $this->paidPhases());

        return hash('sha256', json_encode([
            $this->data['kind'],
            $this->data['catalog_id'],
            $this->data['currency_code'],
            $this->data['cycle'],
            $this->data['trial_days'],
            $this->data['today_charge_minor'],
            $this->data['standard_price_minor'],
            $this->data['previous_price_minor'] ?? null,
            $paid,
        ], JSON_THROW_ON_ERROR));
    }
}
