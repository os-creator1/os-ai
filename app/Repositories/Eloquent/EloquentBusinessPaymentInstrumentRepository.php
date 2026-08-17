<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessPaymentInstrument;
use App\Repositories\Contracts\BusinessPaymentInstrumentRepository;
use Illuminate\Support\Collection;

class EloquentBusinessPaymentInstrumentRepository extends EloquentBaseRepository implements BusinessPaymentInstrumentRepository
{
    public function __construct(BusinessPaymentInstrument $instrument)
    {
        parent::__construct($instrument);
    }

    public function findById(int $id): ?BusinessPaymentInstrument
    {
        return $this->query()->find($id);
    }

    public function findByProviderPaymentMethodId(string $providerPaymentMethodId): ?BusinessPaymentInstrument
    {
        return $this->query()->where('provider_payment_method_id', $providerPaymentMethodId)->first();
    }

    public function findDefaultForProviderCustomer(int $providerCustomerId): ?BusinessPaymentInstrument
    {
        return $this->query()
            ->where('provider_customer_id', $providerCustomerId)
            ->where('status', 'active')
            ->where('is_default', true)
            ->first();
    }

    public function activeForProviderCustomer(int $providerCustomerId): Collection
    {
        return $this->query()
            ->where('provider_customer_id', $providerCustomerId)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();
    }

    public function create(array $attributes): BusinessPaymentInstrument
    {
        /** @var BusinessPaymentInstrument $instrument */
        $instrument = $this->make($attributes);
        $instrument->save();

        return $instrument;
    }

    public function update(BusinessPaymentInstrument $instrument, array $attributes): BusinessPaymentInstrument
    {
        $instrument->fill($attributes);
        $instrument->save();

        return $instrument;
    }

    public function clearDefaultForProviderCustomer(int $providerCustomerId): void
    {
        $this->query()->where('provider_customer_id', $providerCustomerId)->update(['is_default' => false]);
    }
}
