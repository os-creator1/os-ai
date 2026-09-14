<?php

namespace App\Enums\Crm;

/** One kind of entry in a deal's history (crm_opportunity_history.event). */
enum CrmOpportunityHistoryEvent: string
{
    case Created = 'created';
    case StageChanged = 'stage_changed';
    case Won = 'won';
    case Lost = 'lost';
    case Reopened = 'reopened';
    case ContactStatusChanged = 'contact_status_changed';
}
