<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.8/§8.2 — lane-B webhook ingestion state
 * machine (claim/lease pattern).
 */
enum BusinessPaymentEventState: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
