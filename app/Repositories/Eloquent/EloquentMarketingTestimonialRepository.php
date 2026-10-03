<?php

namespace App\Repositories\Eloquent;

use App\Models\MarketingTestimonial;
use App\Repositories\Contracts\MarketingTestimonialRepository;
use Illuminate\Support\Collection;

class EloquentMarketingTestimonialRepository extends EloquentBaseRepository implements MarketingTestimonialRepository
{
    public function __construct(MarketingTestimonial $testimonial)
    {
        parent::__construct($testimonial);
    }

    public function allOrdered(): Collection
    {
        return $this->query()->orderBy('position')->orderBy('id')->get();
    }

    public function visibleOrdered(): Collection
    {
        return $this->query()->visibleOrdered()->get();
    }

    public function nextPosition(): int
    {
        return (int) $this->query()->max('position') + 1;
    }

    public function create(array $attributes): MarketingTestimonial
    {
        /** @var MarketingTestimonial $testimonial */
        $testimonial = $this->query()->create($attributes);

        return $testimonial;
    }

    public function update(MarketingTestimonial $testimonial, array $attributes): MarketingTestimonial
    {
        $testimonial->fill($attributes);
        $testimonial->save();

        return $testimonial;
    }

    public function delete(MarketingTestimonial $testimonial): void
    {
        $testimonial->delete();
    }

    public function isPosterPathInUse(string $path): bool
    {
        return $this->query()->where('poster_image_path', $path)->exists();
    }
}
