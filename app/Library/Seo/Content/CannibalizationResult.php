<?php

namespace App\Library\Seo\Content;

/**
 * SEO Content Engine V1 — what ArticleCannibalizationGuard found. A value object: it carries facts only.
 */
final class CannibalizationResult
{
    /**
     * @param  array<int, array{type: string, uid: string, title: string, strength: string, reason: string}>  $findings
     */
    public function __construct(public readonly array $findings)
    {
    }

    /** True when the topic is the same search as a money page or a duplicate of another article. */
    public function strong(): bool
    {
        return $this->strongFindings() !== [];
    }

    public function conflictsWithPage(): bool
    {
        return count(array_filter($this->findings, fn (array $f) => $f['type'] === 'page' && $f['strength'] === 'strong')) > 0;
    }

    /** @return array<int, array{type: string, uid: string, title: string, strength: string, reason: string}> */
    public function strongFindings(): array
    {
        return array_values(array_filter($this->findings, fn (array $f) => $f['strength'] === 'strong'));
    }

    /** @return array<int, array{type: string, uid: string, title: string, strength: string, reason: string}> */
    public function related(): array
    {
        return array_values(array_filter($this->findings, fn (array $f) => $f['strength'] !== 'strong'));
    }
}
