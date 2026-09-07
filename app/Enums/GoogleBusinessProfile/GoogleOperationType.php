<?php

namespace App\Enums\GoogleBusinessProfile;

/**
 * GBP Slice A contract §11.3.2 — closed set. Every value describes a READ
 * or a local lifecycle transition; there is deliberately no mutation
 * operation type, because Slice A writes nothing to Google (§6, §14.2).
 */
enum GoogleOperationType: string
{
    case ConnectInitiated = 'connect_initiated';
    case ConnectCompleted = 'connect_completed';
    case ConnectFailed = 'connect_failed';
    case TokenRefreshed = 'token_refreshed';
    case Disconnected = 'disconnected';
    case AccountsEnumerated = 'accounts_enumerated';
    case LocationsEnumerated = 'locations_enumerated';
    case LocationBound = 'location_bound';
    case LocationUnbound = 'location_unbound';
    case MirrorRefreshed = 'mirror_refreshed';
    case MirrorPurged = 'mirror_purged';
}
