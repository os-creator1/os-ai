<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\BusinessLocation;

interface BusinessLocationRepository extends BaseRepository
{
    public function findPrimary(Business $business): ?BusinessLocation;

    public function upsertPrimary(Business $business, array $attributes): BusinessLocation;

    public function setPrimary(BusinessLocation $location): BusinessLocation;
}
