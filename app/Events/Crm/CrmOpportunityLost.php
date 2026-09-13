<?php

namespace App\Events\Crm;

/** `opportunity_lost` — an open deal was marked lost, in the stage it was in. */
class CrmOpportunityLost extends CrmOpportunityEvent
{
    public const NAME = 'opportunity_lost';
}
