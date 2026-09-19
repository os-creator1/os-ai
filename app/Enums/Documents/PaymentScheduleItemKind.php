<?php

namespace App\Enums\Documents;

/**
 * Implementation Contract 17 §5.9 — deposit + balance only (Blueprint §34);
 * a version has either one `full` item or exactly `deposit` then `balance`.
 */
enum PaymentScheduleItemKind: string
{
    case Full = 'full';
    case Deposit = 'deposit';
    case Balance = 'balance';
}
