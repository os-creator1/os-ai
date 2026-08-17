<?php

namespace App\Repositories\Eloquent;

use App\Models\PaymentProviderCustomer;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;

class EloquentPaymentProviderCustomerRepository extends EloquentBaseRepository implements PaymentProviderCustomerRepository
{
    public function __construct(PaymentProviderCustomer $customer)
    {
        parent::__construct($customer);
    }

    public function findActiveByBusinessId(int $businessId): ?PaymentProviderCustomer
    {
        return $this->query()->where('business_id', $businessId)->where('status', 'active')->first();
    }

    public function findActiveByWorkspaceId(int $workspaceId): ?PaymentProviderCustomer
    {
        return $this->query()->where('workspace_id', $workspaceId)->where('status', 'active')->first();
    }

    public function findById(int $id): ?PaymentProviderCustomer
    {
        return $this->query()->find($id);
    }

    /**
     * M3 contract §10 item 11 — locks the owning payment_provider_customers
     * row before PaymentInstrumentManager::setDefaultInstrument() clears
     * the prior default and sets the new one, preserving this repository's
     * sole write/lock authority for this table (never a raw DB::table()
     * call from the manager).
     */
    public function findForUpdateById(int $id): ?PaymentProviderCustomer
    {
        return $this->query()->where('id', $id)->lockForUpdate()->first();
    }

    public function create(array $attributes): PaymentProviderCustomer
    {
        /** @var PaymentProviderCustomer $customer */
        $customer = $this->make($attributes);
        $customer->save();

        return $customer;
    }

    public function update(PaymentProviderCustomer $customer, array $attributes): PaymentProviderCustomer
    {
        $customer->fill($attributes);
        $customer->save();

        return $customer;
    }
}
