<?php

namespace App\Enums\Business;

/**
 * Website Guided Generation contract §5.1 -- the closed allowlist. One
 * case per column in business_knowledge_profiles (§4.2), excluding the
 * derived `reviews_source`, plus `hours` -- a valid question-pack and
 * completeness-check key, but never a valid BusinessKnowledgeProfileManager::
 * updateFields() key (its write path is exclusively updateLocationHours(),
 * §5.5). No other string is ever accepted as a field_key anywhere.
 */
enum BusinessKnowledgeProfileFieldKey: string
{
    case VerticalKey = 'vertical_key';
    case PricingMethod = 'pricing_method';
    case FinancingAvailable = 'financing_available';
    case Offers = 'offers';
    case Differentiators = 'differentiators';
    case IdealCustomers = 'ideal_customers';
    case CustomerProblems = 'customer_problems';
    case Credentials = 'credentials';
    case YearsOperating = 'years_operating';
    case WarrantiesGuarantees = 'warranties_guarantees';
    case PrimaryConversionGoal = 'primary_conversion_goal';
    case ConversionTarget = 'conversion_target';
    case BrandVoice = 'brand_voice';
    case ProhibitedClaims = 'prohibited_claims';
    case GrowthPriorityServiceIds = 'growth_priority_service_ids';
    case GrowthPriorityLocationIds = 'growth_priority_location_ids';
    case Testimonials = 'testimonials';
    case Hours = 'hours';

    /**
     * §5.4's locked freshness table groups every case into 90/365/180
     * days, EXCEPT `prohibited_claims`, which the table omits entirely --
     * a genuine gap in the merged contract. It is placed in the 180-day
     * "identity/direction facts, moderate change rate" group by default
     * below: prohibited_claims is exactly that kind of fact (it shapes
     * generation direction rather than asserting a checkable operational
     * or high-stakes claim), so it fits the 180-day group's own stated
     * reasoning better than the 90- or 365-day groups. This is a Slice 1
     * interpretation, not a contract edit -- see the implementation
     * report and BusinessKnowledgeProfileFieldKeyTest for the explicit
     * assertion this depends on.
     */
    public function reconfirmAfterDays(): int
    {
        return match ($this) {
            self::Hours, self::Offers, self::PricingMethod, self::FinancingAvailable => 90,
            self::Credentials, self::YearsOperating, self::WarrantiesGuarantees, self::Testimonials => 365,
            default => 180,
        };
    }
}
