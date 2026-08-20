<?php

namespace App\Repositories\Eloquent;

use App\Models\AdditionalBusinessSlotRenewalCharge;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository;

class EloquentAdditionalBusinessSlotRenewalChargeRepository extends EloquentBaseRepository implements AdditionalBusinessSlotRenewalChargeRepository
{
    public function __construct(AdditionalBusinessSlotRenewalCharge $charge)
    {
        parent::__construct($charge);
    }

    public function findById(int $id): ?AdditionalBusinessSlotRenewalCharge
    {
        return $this->query()->find($id);
    }

    public function findForUpdateById(int $id): ?AdditionalBusinessSlotRenewalCharge
    {
        return $this->query()->where('id', $id)->lockForUpdate()->first();
    }

    public function findByLocalIdempotencyKey(string $key): ?AdditionalBusinessSlotRenewalCharge
    {
        return $this->query()->where('local_idempotency_key', $key)->first();
    }

    public function findByProviderReference(string $reference): ?AdditionalBusinessSlotRenewalCharge
    {
        return $this->query()->where('provider_session_or_intent_reference', $reference)->first();
    }

    public function findByChangeOperationId(string $changeOperationId): ?AdditionalBusinessSlotRenewalCharge
    {
        return $this->query()->where('change_operation_id', $changeOperationId)->first();
    }

    public function create(array $attributes): AdditionalBusinessSlotRenewalCharge
    {
        /** @var AdditionalBusinessSlotRenewalCharge $charge */
        $charge = $this->make($attributes);
        $charge->save();

        return $charge;
    }

    public function update(AdditionalBusinessSlotRenewalCharge $charge, array $attributes): AdditionalBusinessSlotRenewalCharge
    {
        $charge->fill($attributes);
        $charge->save();

        return $charge;
    }
}
