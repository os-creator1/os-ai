<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Repositories\Contracts\BusinessGoogleConnectionRepository;

class EloquentBusinessGoogleConnectionRepository implements BusinessGoogleConnectionRepository
{
    public function findForBusiness(Business $business): ?BusinessGoogleConnection
    {
        return $this->findForBusinessId((int) $business->id);
    }

    public function findForBusinessId(int $businessId): ?BusinessGoogleConnection
    {
        return BusinessGoogleConnection::query()->where('business_id', $businessId)->first();
    }
}
