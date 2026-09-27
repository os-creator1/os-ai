<?php

namespace App\Enums\Messaging;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2/§13.3's audit trail
 * of what happened to a managed number and when. Written exactly once per
 * occurrence by NumberLifecycleManager, the single writer for
 * business_messaging_number_lifecycle_events.
 */
enum NumberLifecycleEventType: string
{
    case RenewalWarningSent = 'renewal_warning_sent';
    case RenewalCharged = 'renewal_charged';
    case Suspended = 'suspended';
    case ReleaseNoticeDelivered = 'release_notice_delivered';
    case ReleaseNoticeDeliveryFailed = 'release_notice_delivery_failed';
    case ReleaseDecided = 'release_decided';
}
