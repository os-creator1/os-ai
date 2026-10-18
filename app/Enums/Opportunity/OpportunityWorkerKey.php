<?php

namespace App\Enums\Opportunity;

enum OpportunityWorkerKey: string
{
    case BusinessAdvisor = 'business_advisor';
    case Seo = 'seo';
    case Content = 'content';
    case Sales = 'sales';
    case Reputation = 'reputation';
    case Website = 'website';
    /** Growth Center: Google/Meta Ads facts (its own run, so an Ads failure never blocks another worker). */
    case Ads = 'ads';
}
