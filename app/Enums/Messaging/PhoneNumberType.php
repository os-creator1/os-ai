<?php

namespace App\Enums\Messaging;

/**
 * Text messaging setup/number/compliance hub — which US carrier
 * registration regime a managed number falls under. `Local` numbers need
 * 10DLC brand+campaign registration; `TollFree` numbers need the
 * materially simpler carrier toll-free verification instead. Never both.
 */
enum PhoneNumberType: string
{
    case Local = 'local';
    case TollFree = 'toll_free';
}
