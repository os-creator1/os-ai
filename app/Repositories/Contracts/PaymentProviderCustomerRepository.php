<?php

namespace App\Repositories\Contracts;

use App\Models\PaymentProviderCustomer;

interface PaymentProviderCustomerRepository extends BaseRepository
{
    public function findActiveByBusinessId(int $businessId): ?PaymentProviderCustomer;

    public function findActiveByWorkspaceId(int $workspaceId): ?PaymentProviderCustomer;

    public function findById(int $id): ?PaymentProviderCustomer;

    public function findForUpdateById(int $id): ?PaymentProviderCustomer;

    public function create(array $attributes): PaymentProviderCustomer;

    public function update(PaymentProviderCustomer $customer, array $attributes): PaymentProviderCustomer;
}
