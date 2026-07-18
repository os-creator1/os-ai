<?php

namespace App\Enums\Business;

enum BusinessGoal: string
{
    case LeadGeneration = 'lead_generation';
    case LocalSeo = 'local_seo';
    case WebsiteConversion = 'website_conversion';
    case Reputation = 'reputation';
    case SalesFollowup = 'sales_followup';
    case Automation = 'automation';
}
