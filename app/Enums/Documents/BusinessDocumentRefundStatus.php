<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.9/§8.7 — pending and succeeded refunds both
 * reserve refundable capacity; a terminal failed refund releases it.
 */
enum BusinessDocumentRefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
