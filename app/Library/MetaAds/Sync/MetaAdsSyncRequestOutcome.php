<?php

namespace App\Library\MetaAds\Sync;

enum MetaAdsSyncRequestOutcome: string
{
    /** A run was created and the job dispatched. */
    case Queued = 'queued';

    /** A manual refresh was already accepted inside the throttle window. */
    case Throttled = 'throttled';

    /** A sync is already claimed, queued or running. */
    case AlreadyRunning = 'already_running';

    /** The cached data is younger than the manual-refresh window; reuse it. */
    case FreshEnough = 'fresh_enough';

    /** Business / workspace / entitlement / connection / selection do not allow a sync. */
    case NotSyncable = 'not_syncable';
}
