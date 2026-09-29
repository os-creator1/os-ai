<?php

namespace App\Repositories\Contracts;

use App\Models\MarketingFaq;
use Illuminate\Support\Collection;

/**
 * Public Marketing Homepage contract, review correction — the repository
 * boundary for FAQ entries, replacing direct Eloquent calls from
 * MarketingContentController and (review correction round 2) from
 * HomeController's public read.
 */
interface MarketingFaqRepository extends BaseRepository
{
    /**
     * @return Collection<int, MarketingFaq> every FAQ, ordered by position,
     *         then id — the admin list's own ordering.
     */
    public function allOrdered(): Collection;

    /**
     * @return Collection<int, MarketingFaq> only visible FAQs, ordered by
     *         position, then id — what the public homepage renders.
     */
    public function visibleOrdered(): Collection;

    /**
     * The position a new, unpositioned FAQ entry should get: after every
     * existing one.
     */
    public function nextPosition(): int;

    public function create(array $attributes): MarketingFaq;

    public function update(MarketingFaq $faq, array $attributes): MarketingFaq;

    public function delete(MarketingFaq $faq): void;
}
