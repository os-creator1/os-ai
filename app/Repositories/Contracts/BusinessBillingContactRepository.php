<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessBillingContact;

interface BusinessBillingContactRepository extends BaseRepository
{
    public function findByBusinessId(int $businessId): ?BusinessBillingContact;

    public function create(array $attributes): BusinessBillingContact;

    public function update(BusinessBillingContact $contact, array $attributes): BusinessBillingContact;
}
