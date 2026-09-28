<?php

namespace App\Repositories\Contracts;

use App\Models\MarketingFaq;
use Illuminate\Support\Collection;

/**
 * Public Marketing Homepage contract, review correction — the repository
 * boundary for FAQ entries, replacing direct Eloquent calls from
 * MarketingContentController.
 */
interface MarketingFaqRepository extends BaseRepository
{
    /**
     * @return Collection<int, MarketingFaq> ordered by position, then id —
     *         the same ordering the admin list and public homepage rely on.
     */
    public function allOrdered(): Collection;

    /**
     * The position a new, unpositioned FAQ entry should get: after every
     * existing one.
     */
    public function nextPosition(): int;

    public function create(array $attributes): MarketingFaq;

    public function update(MarketingFaq $faq, array $attributes): MarketingFaq;

    public function delete(MarketingFaq $faq): void;
}
