<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Implementation Contract 17 §10 — the Blueprint §18 lifecycle transition to
 * `paid`, emitted only once EVERY schedule item of the current version has
 * settled (a paid deposit alone does not qualify).
 */
final class DocumentFullyPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $documentId)
    {
    }
}
