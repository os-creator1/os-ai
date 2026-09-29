<?php

namespace App\Enums\Navigation;

/**
 * Customer navigation coherence pass — the closed set of ways an Available
 * PlatformFeature may legitimately reach the customer, paired with each
 * feature in NavigationDispositionRegistry. This is deliberately NOT a rule
 * that says "every Available feature needs a top-level sidebar entry" — a
 * feature can be genuinely, correctly navigable through a narrower surface,
 * and forcing everything to TopLevel would be its own defect. The point is
 * only that the choice is made on purpose and recorded, so a future flip
 * from Planned to Available cannot silently leave a feature with no
 * customer-reachable destination at all.
 */
enum NavigationDisposition: string
{
    /** A direct entry in the primary Business or Account sidebar. */
    case TopLevel = 'top_level';

    /** Reached only as a child/tab of another top-level module's own pages. */
    case WithinModule = 'within_module';

    /** Surfaced on the Business Home page itself, with no sidebar entry. */
    case BusinessHomeOnly = 'business_home_only';

    /** Reached only through the Settings hub (Business or Account). */
    case SettingsOnly = 'settings_only';

    /** Reached only from the Agency/account frame, never a Business sidebar. */
    case AccountFrameOnly = 'account_frame_only';

    /** Deliberately no customer-facing navigation surface at all. */
    case NoNavigation = 'no_navigation';
}
