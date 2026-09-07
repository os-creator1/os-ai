<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\BusinessGoogleOperation;
use App\Repositories\Contracts\BusinessGoogleOperationRepository;
use Illuminate\Support\Collection;

class EloquentBusinessGoogleOperationRepository implements BusinessGoogleOperationRepository
{
    public function recentForBusiness(Business $business, int $limit = 20): Collection
    {
        return BusinessGoogleOperation::query()
            ->where('business_id', $business->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->get();
    }
}
