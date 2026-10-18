<?php

namespace App\Listeners\Growth;

use App\Events\Crm\CrmOpportunityEvent;
use App\Events\DocumentFullyPaid;
use App\Events\DocumentPaymentFailed;
use App\Events\Website\WebsitePublished;
use App\Library\Growth\GrowthEvaluationTrigger;
use Illuminate\Support\Facades\DB;

/**
 * Growth Center is a CONSUMER of domain events, never a producer of new ones:
 * these existing events only ask for a debounced re-evaluation, because each
 * one can change a Growth finding immediately (a failed payment appears; a
 * paid balance, a published Website or a closed deal resolves one).
 *
 * Every gate lives in GrowthEvaluationTrigger; with the Opportunity Engine
 * disabled these handlers do nothing. A listener that cannot tell which
 * Business an event belongs to does nothing rather than guess.
 */
class TriggerGrowthEvaluation
{
    public function __construct(private readonly GrowthEvaluationTrigger $trigger)
    {
    }

    public function handlePaymentFailed(DocumentPaymentFailed $event): void
    {
        $this->forBusiness($event->businessId ?? $this->businessOfDocument($event->documentId));
    }

    public function handleFullyPaid(DocumentFullyPaid $event): void
    {
        $this->forBusiness($event->businessId ?? $this->businessOfDocument($event->documentId));
    }

    public function handleWebsitePublished(WebsitePublished $event): void
    {
        $this->forBusiness($event->businessId ?? null);
    }

    public function handleCrmDealClosed(CrmOpportunityEvent $event): void
    {
        $this->forBusiness($event->businessId);
    }

    private function forBusiness(?int $businessId): void
    {
        if ($businessId !== null) {
            $this->trigger->triggerFromEvent($businessId);
        }
    }

    private function businessOfDocument(int $documentId): ?int
    {
        $id = DB::table('business_documents')->where('id', $documentId)->value('business_id');

        return $id === null ? null : (int) $id;
    }
}
