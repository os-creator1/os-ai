<?php

namespace App\Events\Crm;

/** `opportunity_won` — an open deal was marked won, in the stage it was in. */
class CrmOpportunityWon extends CrmOpportunityEvent
{
    public const NAME = 'opportunity_won';
}
