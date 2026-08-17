<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessPaymentInstrument;
use Illuminate\Support\Collection;

interface BusinessPaymentInstrumentRepository extends BaseRepository
{
    public function findById(int $id): ?BusinessPaymentInstrument;

    public function findByProviderPaymentMethodId(string $providerPaymentMethodId): ?BusinessPaymentInstrument;

    public function findDefaultForProviderCustomer(int $providerCustomerId): ?BusinessPaymentInstrument;

    /**
     * @return Collection<int, BusinessPaymentInstrument>
     */
    public function activeForProviderCustomer(int $providerCustomerId): Collection;

    public function create(array $attributes): BusinessPaymentInstrument;

    public function update(BusinessPaymentInstrument $instrument, array $attributes): BusinessPaymentInstrument;

    public function clearDefaultForProviderCustomer(int $providerCustomerId): void;
}
