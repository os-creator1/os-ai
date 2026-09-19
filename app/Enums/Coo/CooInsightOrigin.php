<?php

namespace App\Enums\Coo;

/**
 * Implementation Contract 19 §5.9 — who caused this row to exist, and
 * therefore who may read it back.
 *
 * `System` is a background generation. No human asked for it, so
 * `actor_user_id` is NULL and nothing is ever attributed to whoever reads it
 * next. It is computed for a declared audience (§5.9b), recorded in
 * `audience_user_id`, which is an authorization input and never audit
 * attribution.
 *
 * `OnDemand` is an explicit human request — today "Explain this change",
 * later Ask and Draft. `actor_user_id` is the real acting human, and the row
 * is selectable only for that same actor (R-26).
 *
 * Both columns are immutable after insert (R-25): a `System` row is never
 * promoted to `OnDemand` and an `OnDemand` row is never demoted to `System`
 * to widen its audience (R-27).
 */
enum CooInsightOrigin: string
{
    case System = 'system';

    case OnDemand = 'on_demand';

    /** True when this origin requires a real acting human on the row. */
    public function requiresActor(): bool
    {
        return $this === self::OnDemand;
    }
}
