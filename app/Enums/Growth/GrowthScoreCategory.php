<?php

namespace App\Enums\Growth;

/** The nine explainable Growth Score categories (Growth Center §6). */
enum GrowthScoreCategory: string
{
    case LeadGeneration = 'lead_generation';
    case LeadResponse = 'lead_response';
    case SalesConversion = 'sales_conversion';
    case Booking = 'booking';
    case Website = 'website';
    case Seo = 'seo';
    case Ads = 'ads';
    case Reviews = 'reviews';
    case LocalPresence = 'local_presence';

    public function label(): string
    {
        return match ($this) {
            self::LeadGeneration => 'Lead generation',
            self::LeadResponse => 'Lead response',
            self::SalesConversion => 'Sales conversion',
            self::Booking => 'Booking readiness',
            self::Website => 'Website',
            self::Seo => 'SEO',
            self::Ads => 'Ads',
            self::Reviews => 'Reviews & reputation',
            self::LocalPresence => 'Local presence',
        };
    }
}
