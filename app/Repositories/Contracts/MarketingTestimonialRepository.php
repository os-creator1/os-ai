<?php

namespace App\Repositories\Contracts;

use App\Models\MarketingTestimonial;
use Illuminate\Support\Collection;

/**
 * Public Marketing Homepage contract, review correction — the repository
 * boundary for video testimonial entries, replacing direct Eloquent calls
 * from MarketingContentController.
 */
interface MarketingTestimonialRepository extends BaseRepository
{
    /**
     * @return Collection<int, MarketingTestimonial> ordered by position,
     *         then id.
     */
    public function allOrdered(): Collection;

    /**
     * The position a new, unpositioned testimonial should get: after
     * every existing one.
     */
    public function nextPosition(): int;

    public function create(array $attributes): MarketingTestimonial;

    public function update(MarketingTestimonial $testimonial, array $attributes): MarketingTestimonial;

    public function delete(MarketingTestimonial $testimonial): void;

    /**
     * Whether any testimonial row — of any visibility — still points at
     * this poster path. The single source of truth for the
     * shared-content-hashed-poster orphan check: a path may only be
     * deleted from disk once this returns false.
     */
    public function isPosterPathInUse(string $path): bool;
}
