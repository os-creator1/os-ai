<?php

namespace App\Enums\Business;

/**
 * Website Guided Generation contract §4.2/§9 -- `business_knowledge_
 * profiles.pricing_method` and `offers[].pricing_method_override` are
 * both enum-backed to exactly these four values. `financing_available`
 * is deliberately not a case of this enum (§9 correction) -- it is its
 * own independent, separately-tracked field.
 */
enum BusinessPricingMethod: string
{
    case Fixed = 'fixed';
    case Hourly = 'hourly';
    case QuoteOnly = 'quote_only';
    case PackageTiers = 'package_tiers';
}
