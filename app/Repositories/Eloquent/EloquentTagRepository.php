<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\Tag;
use App\Repositories\Contracts\TagRepository;
use Illuminate\Support\Collection;

class EloquentTagRepository extends EloquentBaseRepository implements TagRepository
{
    public function __construct(Tag $tag)
    {
        parent::__construct($tag);
    }

    public function findByUid(Business $business, string $uid): ?Tag
    {
        return $this->query()
            ->where('business_id', $business->id)
            ->where('uid', $uid)
            ->first();
    }

    public function findByNormalizedName(Business $business, string $normalizedName): ?Tag
    {
        return $this->query()
            ->where('business_id', $business->id)
            ->where('normalized_name', $normalizedName)
            ->first();
    }

    public function create(Business $business, string $name, string $normalizedName): Tag
    {
        return Tag::query()->create([
            'business_id' => $business->id,
            'name' => $name,
            'normalized_name' => $normalizedName,
        ]);
    }

    public function rename(Tag $tag, string $name, string $normalizedName): Tag
    {
        $tag->forceFill([
            'name' => $name,
            'normalized_name' => $normalizedName,
        ])->save();

        return $tag;
    }

    public function archive(Tag $tag): Tag
    {
        if (! $tag->isArchived()) {
            $tag->forceFill(['archived_at' => now()])->save();
        }

        return $tag;
    }

    public function listForBusiness(Business $business, bool $includeArchived = false): Collection
    {
        $query = $this->query()->where('business_id', $business->id);

        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }

        return $query->orderBy('name')->get();
    }
}
