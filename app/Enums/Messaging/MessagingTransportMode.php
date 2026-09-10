<?php

namespace App\Enums\Messaging;

/**
 * Slice 3 §4.2 — whether a recorded operation travelled over the platform's
 * managed transport or a Business's own BYO connection.
 */
enum MessagingTransportMode: string
{
    case Managed = 'managed';
    case Byo = 'byo';
}
