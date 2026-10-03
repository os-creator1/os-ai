<?php

namespace App\Repositories\Contracts;

use App\Models\MarketingTestimonial;
use Illuminate\Support\Collection;

/**
 * Public Marketing Homepage contract, review correction — the repository
 * boundary for video testimonial entries, replacing direct Eloquent calls
 * from MarketingContentController and (review correction round 2) from
 * HomeController's public read.
 */
interface MarketingTestimonialRepository extends BaseRepository
{
    /**
     * @return Collection<int, MarketingTestimonial> every testimonial,
     *         ordered by position, then id — the admin list's own ordering.
     */
    public function allOrdered(): Collection;

    /**
     * @return Collection<int, MarketingTestimonial> only visible
     *         testimonials, ordered by position, then id. Still includes a
     *         visible-but-not-yet-media-complete row (e.g. marked visible
     *         before a poster/video was added) — HomeController applies
     *         MarketingTestimonial::hasDisplayableMedia() on top, the same
     *         plain Collection filter it already applied before this
     *         correction, just now over a repository-sourced Collection
     *         instead of a direct Eloquent scope call.
     */
    public function visibleOrdered(): Collection;

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
