<?php

namespace App\Events\Crm;

/** `opportunity_created` — a deal now exists, in the stage it was created in. */
class CrmOpportunityCreated extends CrmOpportunityEvent
{
    public const NAME = 'opportunity_created';
}
