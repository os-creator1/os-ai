<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\PhoneNumberType;

/**
 * The scoped retail meters for managed messaging. Each is a price per
 * BILLABLE PART (an SMS segment or an MMS) at the owner-approved retail
 * base; Telnyx's actual carrier surcharge is added on top through the
 * SURCHARGE meter (a fixed multiplier of 1.00, i.e. the surcharge at cost).
 * BYO Telnyx traffic never reaches any of these.
 */
final class MessageMeters
{
    public const SMS_OUTBOUND = 'messaging_sms_outbound';

    public const SMS_INBOUND = 'messaging_sms_inbound';

    public const MMS_OUTBOUND = 'messaging_mms_outbound';

    public const MMS_INBOUND_LOCAL = 'messaging_mms_inbound_local';

    public const MMS_INBOUND_TOLL_FREE = 'messaging_mms_inbound_toll_free';

    /** Actual carrier surcharge, passed through at exactly 1.00x. Also carries settlement debits. */
    public const SURCHARGE = 'messaging_carrier_surcharge';

    public const ALL_RETAIL = [self::SMS_OUTBOUND, self::SMS_INBOUND, self::MMS_OUTBOUND, self::MMS_INBOUND_LOCAL, self::MMS_INBOUND_TOLL_FREE];

    /** @param 'sms'|'mms' $messageType  @param 'outbound'|'inbound' $direction */
    public static function for(string $messageType, string $direction, ?string $numberType): string
    {
        $outbound = $direction === 'outbound';

        if ($messageType === 'mms') {
            if ($outbound) {
                return self::MMS_OUTBOUND;
            }

            return $numberType === PhoneNumberType::TollFree->value ? self::MMS_INBOUND_TOLL_FREE : self::MMS_INBOUND_LOCAL;
        }

        return $outbound ? self::SMS_OUTBOUND : self::SMS_INBOUND;
    }
}
