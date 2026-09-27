<?php

namespace App\Repositories\Eloquent;

use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Repositories\Contracts\BusinessGoogleConnectionRepository;

class EloquentBusinessGoogleConnectionRepository implements BusinessGoogleConnectionRepository
{
    public function findForBusiness(Business $business, GoogleConnectionProduct $product): ?BusinessGoogleConnection
    {
        return $this->findForBusinessId((int) $business->id, $product);
    }

    public function findForBusinessId(int $businessId, GoogleConnectionProduct $product): ?BusinessGoogleConnection
    {
        return BusinessGoogleConnection::query()
            ->where('business_id', $businessId)
            ->where('product', $product->value)
            ->first();
    }
}
