<?php

namespace App\Library\Assistant;

/** What an executed action actually did — read back from the domain mechanism's own answer, never assumed. */
final readonly class ActionOutcome
{
    /** @param array<string, mixed> $details */
    public function __construct(public string $message, public ?string $url = null, public array $details = [])
    {
    }
}
