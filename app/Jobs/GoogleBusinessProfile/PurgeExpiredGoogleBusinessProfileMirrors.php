<?php

namespace App\Jobs\GoogleBusinessProfile;

use App\Jobs\Base;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService;
use App\Models\BusinessGoogleLocation;

/**
 * GBP Slice A contract §13.2 — the Google-content retention purge, and the
 * enforcement half of the mandatory correction this slice carries
 * (Appendix A, A-1).
 *
 * Google's API policy requires that stored Content "must be stored
 * temporarily for no more than 30 calendar days". Every mirror is written
 * with mirror_expires_at <= mirror_fetched_at + 30 calendar days
 * (GoogleBusinessProfileRetention), and this job removes anything past it.
 *
 * Structurally mirrors App\Jobs\Usage\PurgeExpiredWebhookPayloads,
 * scheduled hourly in App\Console\Kernel — but with the fail-closed
 * direction deliberately INVERTED. For webhook payloads, failing closed
 * means do not purge, because purging destroys evidence. Here, an absent
 * or invalid retention configuration yields an effective TTL of ZERO, so
 * everything is expired and everything is purged: keeping Google Content
 * too long breaches Google's terms, and that is the risk this job exists
 * to bound.
 *
 * It nulls only the Google Content and the three bind-time snapshot
 * fields. Provider resource names, business_location_id and the
 * verification/state booleans survive: they are operational binding
 * metadata, not a Google-content archive (contract §13.7).
 */
class PurgeExpiredGoogleBusinessProfileMirrors extends Base
{
    private const CHUNK = 100;

    public function handle(GoogleBusinessProfileMirrorService $mirror): void
    {
        $now = now();

        BusinessGoogleLocation::query()
            ->whereNotNull('mirror_expires_at')
            ->where('mirror_expires_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($bindings) use ($mirror) {
                foreach ($bindings as $binding) {
                    $mirror->purge($binding);
                }
            });
    }
}
