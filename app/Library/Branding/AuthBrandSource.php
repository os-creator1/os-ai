<?php

namespace App\Library\Branding;

/**
 * Customer Experience Slice 2 — which identity an authentication screen is
 * showing (contract §9.1 precedence: Agency → owner platform → neutral).
 */
enum AuthBrandSource: string
{
    /** The product's own neutral identity: "AI Business OS", no artwork. */
    case Neutral = 'neutral';

    /** The platform owner's configured name/logo/illustration (config/app). */
    case Platform = 'platform';

    /** An authorized Agency white-label brand resolved for this request. */
    case Agency = 'agency';
}
