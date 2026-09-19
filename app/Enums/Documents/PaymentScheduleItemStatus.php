<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.9 — schedule-item PROGRESS (not commercial
 * content). `refunded` only when cumulative succeeded refunds equal the full
 * captured amount for that item.
 */
enum PaymentScheduleItemStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case Void = 'void';
}
