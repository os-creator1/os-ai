<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthFactStatus;

/**
 * One domain's facts for one Business, plus whether they may be judged at all.
 *
 * `$data` is a plain array shaped by the owning reader (documented on each
 * reader). A rule must check `isAvailable()` first; the rule evaluator does
 * that for it, so a rule body only ever runs against Available facts.
 */
final class GrowthFactSet
{
    /** @param  array<string, mixed>  $data */
    private function __construct(
        public readonly string $domain,
        public readonly GrowthFactStatus $status,
        public readonly array $data,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public static function available(string $domain, array $data): self
    {
        return new self($domain, GrowthFactStatus::Available, $data);
    }

    public static function withStatus(string $domain, GrowthFactStatus $status): self
    {
        return new self($domain, $status, []);
    }

    public function isAvailable(): bool
    {
        return $this->status->isAvailable();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
