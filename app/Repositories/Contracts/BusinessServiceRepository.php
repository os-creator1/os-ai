<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\BusinessService;
use Illuminate\Support\Collection;

interface BusinessServiceRepository extends BaseRepository
{
    public function activeForBusiness(Business $business): Collection;

    public function findOwned(Business $business, int $serviceId): ?BusinessService;

    public function syncForBusiness(Business $business, array $services): Collection;

    public function setPrimary(BusinessService $service): BusinessService;
}
