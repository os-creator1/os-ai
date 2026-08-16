<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessBillingContact;
use App\Repositories\Contracts\BusinessBillingContactRepository;

class EloquentBusinessBillingContactRepository extends EloquentBaseRepository implements BusinessBillingContactRepository
{
    public function __construct(BusinessBillingContact $contact)
    {
        parent::__construct($contact);
    }

    public function findByBusinessId(int $businessId): ?BusinessBillingContact
    {
        return $this->query()->where('business_id', $businessId)->first();
    }

    public function create(array $attributes): BusinessBillingContact
    {
        /** @var BusinessBillingContact $contact */
        $contact = $this->make($attributes);
        $contact->save();

        return $contact;
    }

    public function update(BusinessBillingContact $contact, array $attributes): BusinessBillingContact
    {
        $contact->fill($attributes);
        $contact->save();

        return $contact;
    }
}
