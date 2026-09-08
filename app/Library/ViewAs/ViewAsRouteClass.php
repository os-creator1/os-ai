<?php

namespace App\Library\ViewAs;

/**
 * How a route behaves while a View-as-client session is active
 * (contract §5.5 "Authorization": every check stays constrained to the
 * viewed Business; view-as only ever narrows).
 */
enum ViewAsRouteClass: string
{
    /** Carries `businessUid`: allowed only for the viewed Business. */
    case BusinessScoped = 'business_scoped';

    /** A bare module entry: redirected into the viewed Business. */
    case RedirectToViewed = 'redirect_to_viewed';

    /** Explicitly classified as safe, account-independent behaviour. */
    case Safe = 'safe';

    /** A §5.5 prohibited action: refused with a plain message and audited. */
    case Prohibited = 'prohibited';

    /** Everything else that reaches outside the viewed Business: 404. */
    case Denied = 'denied';

    /** Not in any closed inventory — the boundary test fails on this. */
    case Unclassified = 'unclassified';
}
