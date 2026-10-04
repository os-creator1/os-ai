<?php

namespace App\Enums\Growth;

/**
 * Owner-facing rule categories (the Growth Center's filter chips).
 * A category is a presentation grouping; `scoreCategory()` says which of the
 * nine explainable score categories a rule's health feeds, or null when a
 * category does not feed the Growth Score (Forms/Automations in V1).
 */
enum GrowthCategory: string
{
    case LeadGeneration = 'lead_generation';
    case LeadResponse = 'lead_response';
    case SalesPipeline = 'sales_pipeline';
    case Bookings = 'bookings';
    case Website = 'website';
    case Forms = 'forms';
    case Seo = 'seo';
    case Ads = 'ads';
    case Reviews = 'reviews';
    case LocalPresence = 'local_presence';
    case ProposalsSales = 'proposals_sales';
    case Payments = 'payments';
    case Automations = 'automations';

    public function label(): string
    {
        return match ($this) {
            self::LeadGeneration => 'Lead generation',
            self::LeadResponse => 'Lead response',
            self::SalesPipeline => 'Sales pipeline',
            self::Bookings => 'Bookings',
            self::Website => 'Website',
            self::Forms => 'Forms',
            self::Seo => 'SEO',
            self::Ads => 'Ads',
            self::Reviews => 'Reviews',
            self::LocalPresence => 'Local presence',
            self::ProposalsSales => 'Proposals & sales',
            self::Payments => 'Payments',
            self::Automations => 'Automations',
        };
    }

    public function scoreCategory(): ?GrowthScoreCategory
    {
        return match ($this) {
            self::LeadGeneration => GrowthScoreCategory::LeadGeneration,
            self::LeadResponse => GrowthScoreCategory::LeadResponse,
            self::SalesPipeline, self::ProposalsSales, self::Payments => GrowthScoreCategory::SalesConversion,
            self::Bookings => GrowthScoreCategory::Booking,
            self::Website, self::Forms => GrowthScoreCategory::Website,
            self::Seo => GrowthScoreCategory::Seo,
            self::Ads => GrowthScoreCategory::Ads,
            self::Reviews => GrowthScoreCategory::Reviews,
            self::LocalPresence => GrowthScoreCategory::LocalPresence,
            self::Automations => null,
        };
    }
}
