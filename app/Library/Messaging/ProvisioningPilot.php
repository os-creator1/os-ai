<?php

namespace App\Library\Messaging;

use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Models\Business;

/**
 * The pilot restriction on real provisioning. Closed to everyone unless the
 * Business is explicitly listed (MESSAGING_PILOT_BUSINESS_IDS) or the
 * platform owner has deliberately opened it to all customers.
 */
final class ProvisioningPilot
{
    public static function allows(Business|int $business): bool
    {
        if (config('messaging.provisioning_open_to_all')) {
            return true;
        }

        $id = $business instanceof Business ? (int) $business->id : $business;
        $listed = array_filter(array_map('trim', explode(',', (string) config('messaging.provisioning_pilot_business_ids'))), fn ($v) => $v !== '' && ctype_digit($v));

        return in_array((string) $id, $listed, true);
    }

    /** @throws MessagingProviderNotConfiguredException when this Business is not in the pilot */
    public static function assertOpen(Business|int $business): void
    {
        if (! self::allows($business)) {
            throw new MessagingProviderNotConfiguredException('Real provisioning is limited to the pilot: this Business is not enabled for it.');
        }
    }
}
