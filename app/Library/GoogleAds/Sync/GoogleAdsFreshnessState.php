<?php

namespace App\Library\GoogleAds\Sync;

enum GoogleAdsFreshnessState: string
{
    /** A sync is claimed, queued or running right now. */
    case Running = 'running';

    /** No sync has ever succeeded: there is no data to show. */
    case NeverSynced = 'never_synced';

    /** The latest sync did not complete cleanly; the last good data is shown with a warning. */
    case FailedWithData = 'failed_with_data';

    /** The last sync succeeded but is older than twice the refresh interval. */
    case Stale = 'stale';

    case Fresh = 'fresh';
}
