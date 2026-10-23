<?php

namespace App\Library\Migration\Ghl;

final readonly class GhlApiResult
{
    /** @param array<string, mixed> $body */
    public function __construct(public int $status, public array $body)
    {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
