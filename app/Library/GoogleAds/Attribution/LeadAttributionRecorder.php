<?php

namespace App\Library\GoogleAds\Attribution;

use App\Enums\GoogleAds\LeadAttributionEntrySurface;
use App\Enums\GoogleAds\LeadAttributionSubjectType;
use App\Enums\GoogleAds\LeadAttributionTouchRole;
use App\Http\Middleware\CaptureAttributionTouch;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Models\Business;
use App\Models\LeadAttributionTouch;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Google Ads Module V1 contract §10 — the one writer of lead_attribution_touches.
 *
 * Called by the three public entry points AFTER the conversion has committed
 * and its subject is known. Append-only and idempotent per
 * (subject_type, subject_id, touch_role) via the table's unique key. The
 * Business is the one the SERVER resolved for the public page; nothing in a
 * cookie can choose it. It never throws: a failure is logged without any
 * visitor value and the submission carries on.
 */
class LeadAttributionRecorder
{
    /**
     * Writes one `first` row and, when the last touch differs, one `last` row.
     * When only one cookie is valid it is the first touch.
     *
     * @return int rows newly written (0 when nothing to record, opted out, or already recorded)
     */
    public function record(
        Business|int $business,
        ?int $businessLocationId,
        ?int $contactId,
        LeadAttributionSubjectType $subjectType,
        int $subjectId,
        LeadAttributionEntrySurface $surface,
        Request $request,
    ): int {
        try {
            if (! (new GoogleAdsConfig)->attributionCaptureEnabled() || CaptureAttributionTouch::visitorOptedOut($request)) {
                return 0;
            }

            $first = AttributionCookie::read($request, AttributionCookie::FIRST);
            $last = AttributionCookie::read($request, AttributionCookie::LAST);
            $first ??= $last;

            if ($first === null) {
                return 0;
            }

            $businessId = $business instanceof Business ? (int) $business->id : $business;
            $written = 0;

            $roles = [[LeadAttributionTouchRole::First, $first]];
            if ($last !== null && ! $last->sameTouchAs($first)) {
                $roles[] = [LeadAttributionTouchRole::Last, $last];
            }

            foreach ($roles as [$role, $touch]) {
                $written += $this->insert($businessId, $businessLocationId, $contactId, $subjectType, $subjectId, $surface, $role, $touch);
            }

            return $written;
        } catch (\Throwable $exception) {
            Log::warning('Lead attribution could not be recorded.', [
                'exception' => $exception::class,
                'subject_type' => $subjectType->value,
                'subject_id' => $subjectId,
            ]);

            return 0;
        }
    }

    /**
     * Attaches a contact to rows recorded before the contact was known. Fills
     * ONLY a NULL contact_id, only for a contact of the same Business, via a
     * guarded query-builder update (the model refuses updates).
     *
     * @return int rows linked
     */
    public function linkContact(Business|int $business, LeadAttributionSubjectType $subjectType, int $subjectId, int $contactId): int
    {
        $businessId = $business instanceof Business ? (int) $business->id : $business;

        try {
            $contactIsOurs = DB::table('contacts')->where('id', $contactId)->where('business_id', $businessId)->exists();

            if (! $contactIsOurs) {
                return 0;
            }

            return DB::table('lead_attribution_touches')
                ->where('business_id', $businessId)
                ->where('subject_type', $subjectType->value)
                ->where('subject_id', $subjectId)
                ->whereNull('contact_id')
                ->update(['contact_id' => $contactId]);
        } catch (\Throwable $exception) {
            Log::warning('Lead attribution contact could not be linked.', ['exception' => $exception::class]);

            return 0;
        }
    }

    private function insert(
        int $businessId,
        ?int $businessLocationId,
        ?int $contactId,
        LeadAttributionSubjectType $subjectType,
        int $subjectId,
        LeadAttributionEntrySurface $surface,
        LeadAttributionTouchRole $role,
        AttributionParameters $touch,
    ): int {
        $now = CarbonImmutable::now();
        $captured = $touch->capturedAt() ?? $now;

        try {
            LeadAttributionTouch::query()->create([
                'business_id' => $businessId,
                'business_location_id' => $businessLocationId,
                'contact_id' => $contactId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'entry_surface' => $surface,
                'touch_role' => $role,
                ...$touch->columns(),
                'landing_page' => $touch->landingPage(),
                'captured_at' => $captured,
                'recorded_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return 0; // already recorded for this event: idempotent.
        }

        return 1;
    }
}
