<?php

namespace App\Repositories\Eloquent;

use App\Models\AdditionalBusinessSlotAgreement;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use Illuminate\Support\Collection;

class EloquentAdditionalBusinessSlotAgreementRepository extends EloquentBaseRepository implements AdditionalBusinessSlotAgreementRepository
{
    public function __construct(AdditionalBusinessSlotAgreement $agreement)
    {
        parent::__construct($agreement);
    }

    public function findById(int $id): ?AdditionalBusinessSlotAgreement
    {
        return $this->query()->find($id);
    }

    public function findForUpdateById(int $id): ?AdditionalBusinessSlotAgreement
    {
        return $this->query()->where('id', $id)->lockForUpdate()->first();
    }

    public function findByLocalIdempotencyKey(string $key): ?AdditionalBusinessSlotAgreement
    {
        return $this->query()->where('local_idempotency_key', $key)->first();
    }

    public function findByProviderReference(string $reference): ?AdditionalBusinessSlotAgreement
    {
        return $this->query()->where('provider_session_or_intent_reference', $reference)->first();
    }

    public function findDueForRenewal(): Collection
    {
        return $this->query()
            ->where('next_renewal_at', '<=', now())
            ->where('cancel_at_period_end', false)
            ->where('payment_lapsed', false)
            ->where('state', 'completed')
            ->get();
    }

    public function findDueForCancellationFinalization(): Collection
    {
        return $this->query()
            ->where('cancel_at_period_end', true)
            ->where('cancellation_effective_at', '<=', now())
            ->where('state', '!=', 'canceled')
            ->get();
    }

    public function findRequiringAllocationRecovery(int $thresholdMinutes): Collection
    {
        $threshold = now()->subMinutes($thresholdMinutes);

        return $this->query()
            ->where(function ($query) use ($threshold) {
                $query->whereIn('state', ['payment_succeeded', 'allocation_pending'])
                    ->where('updated_at', '<=', $threshold);
            })
            ->orWhere('state', 'allocation_failed')
            ->get();
    }

    public function create(array $attributes): AdditionalBusinessSlotAgreement
    {
        /** @var AdditionalBusinessSlotAgreement $agreement */
        $agreement = $this->make($attributes);
        $agreement->save();

        return $agreement;
    }

    public function update(AdditionalBusinessSlotAgreement $agreement, array $attributes): AdditionalBusinessSlotAgreement
    {
        $agreement->fill($attributes);
        $agreement->save();

        return $agreement;
    }
}
