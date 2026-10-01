<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\Tag;
use Illuminate\Support\Collection;

/**
 * Contact Tags foundation §3 — pure persistence for the `tags` table.
 * Carries no tenancy decision of its own beyond scoping every query to the
 * given Business; the uniqueness/archiving RULES live in `TagManager`, the
 * one canonical boundary above this repository.
 */
interface TagRepository extends BaseRepository
{
    public function findByUid(Business $business, string $uid): ?Tag;

    public function findByNormalizedName(Business $business, string $normalizedName): ?Tag;

    public function create(Business $business, string $name, string $normalizedName): Tag;

    public function rename(Tag $tag, string $name, string $normalizedName): Tag;

    public function archive(Tag $tag): Tag;

    /** @return Collection<int, Tag> */
    public function listForBusiness(Business $business, bool $includeArchived = false): Collection;
}
