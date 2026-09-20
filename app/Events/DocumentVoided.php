<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DocumentVoided
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $documentId) {}
}
