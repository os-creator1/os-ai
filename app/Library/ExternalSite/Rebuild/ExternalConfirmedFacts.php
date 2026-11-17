<?php

namespace App\Library\ExternalSite\Rebuild;

use App\Models\Business;
use App\Models\ExternalRebuildPlan;

/**
 * The facts the OWNER confirmed on the rebuild plan, reduced to the closed vocabularies the wizard can safely
 * prefill. Nothing else from the audited site is ever offered: no copied text, no claims, no reviews, no images.
 *
 *  - areas:  only names from ExternalRebuildPlanner::GTA_AREAS (a stored label is re-checked against that list)
 *  - events: only the event kinds the planner can detect, each mapped to the questionnaire's own preset key
 *
 * An unconfirmed fact, an unconfirmed plan, or another Business's plan yields nothing. The plan is always looked up by
 * business_id.
 */
final class ExternalConfirmedFacts
{
    /** planner event kind => the Photo Booth questionnaire's event preset key (the wording stays the platform's own). */
    public const EVENT_PRESETS = ['corporate' => 'corporate', 'wedding' => 'weddings', 'party' => 'private_parties'];

    /** @return array{areas: list<string>, events: list<string>} */
    public static function forBusiness(Business $business): array
    {
        $none = ['areas' => [], 'events' => []];
        $plan = ExternalRebuildPlan::where('business_id', $business->id)->first();

        if ($plan === null || ! $plan->isConfirmed()) {
            return $none;
        }

        $areas = [];
        $events = [];

        foreach ((array) $plan->facts as $fact) {
            if (! ($fact['confirmed'] ?? false)) {
                continue;
            }

            if (($fact['type'] ?? null) === 'service_area') {
                foreach (ExternalRebuildPlanner::GTA_AREAS as $allowed) {
                    if (strcasecmp((string) ($fact['label'] ?? ''), $allowed) === 0) {
                        $areas[] = $allowed;
                    }
                }
            } elseif (($fact['type'] ?? null) === 'event_type') {
                $kind = (string) substr((string) ($fact['key'] ?? ''), strlen('event:'));
                if (isset(self::EVENT_PRESETS[$kind])) {
                    $events[] = $kind;
                }
            }
        }

        return ['areas' => array_values(array_unique($areas)), 'events' => array_values(array_unique($events))];
    }
}
