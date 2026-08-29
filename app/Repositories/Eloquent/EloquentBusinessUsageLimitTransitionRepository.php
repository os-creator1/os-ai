<?php

namespace App\Repositories\Eloquent;

use App\Enums\Usage\UsageLimitType;
use App\Models\BusinessUsageLimitTransition;
use App\Repositories\Contracts\BusinessUsageLimitTransitionRepository;
use Illuminate\Support\Collection;

class EloquentBusinessUsageLimitTransitionRepository extends EloquentBaseRepository implements BusinessUsageLimitTransitionRepository
{
    public function __construct(BusinessUsageLimitTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): BusinessUsageLimitTransition
    {
        /** @var BusinessUsageLimitTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }

    public function recentForBusiness(int $businessId, int $limit = 20): Collection
    {
        return $this->query()
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function recentPlatformSafetyLimitHistory(int $limit = 50): Collection
    {
        return $this->query()
            ->whereNull('business_id')
            ->where('limit_type', UsageLimitType::PlatformSafetyLimit->value)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
