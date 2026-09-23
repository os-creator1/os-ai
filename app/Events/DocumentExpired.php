<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Implementation Contract 17 §10 — the Blueprint §18 lifecycle transition to
 * `expired`.
 *
 * §8.6 makes this narrow on purpose: only an UNSIGNED, UNPAID `sent` document
 * can reach it, so this event can never announce that signed or partly-paid
 * money was stranded.
 */
final class DocumentExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $documentId)
    {
    }
}
