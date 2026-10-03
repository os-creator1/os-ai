<?php

namespace App\Repositories\Eloquent;

use App\Models\MarketingFaq;
use App\Repositories\Contracts\MarketingFaqRepository;
use Illuminate\Support\Collection;

class EloquentMarketingFaqRepository extends EloquentBaseRepository implements MarketingFaqRepository
{
    public function __construct(MarketingFaq $faq)
    {
        parent::__construct($faq);
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

    public function create(array $attributes): MarketingFaq
    {
        /** @var MarketingFaq $faq */
        $faq = $this->query()->create($attributes);

        return $faq;
    }

    public function update(MarketingFaq $faq, array $attributes): MarketingFaq
    {
        $faq->fill($attributes);
        $faq->save();

        return $faq;
    }

    public function delete(MarketingFaq $faq): void
    {
        $faq->delete();
    }
}
