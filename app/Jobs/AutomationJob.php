<?php

namespace App\Jobs;

use App\Enums\Automation\AutomationTriggerType;
use App\Library\Automation\AutomationEligibility;
use App\Library\Automation\AutomationExecutionClaimService;
use App\Library\Automation\AutomationTriggerEvaluator;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\Contacts;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * B4 Business Automations — the TRIGGER-EVALUATION job (contract §9, §10).
 * The class name is retained from the legacy worker pattern; its
 * responsibility is now: for one trigger occurrence, determine the due
 * Contacts, claim exactly one execution per (automation, contact,
 * occurrence) through AutomationExecutionClaimService, and hand each
 * claimed execution to the action job. It never calls a provider and
 * never sends anything itself.
 *
 * Two entry modes, both on the existing `automation` queue:
 *   - forDateSweep(automationId): dispatched by `automation:run` every
 *     five minutes for each active, Business-scoped CONTACT_DATE_REACHED
 *     automation.
 *   - forContactCreated(contactId): dispatched (after commit) from the two
 *     in-scope CRM creation seams for a freshly committed Business-scoped
 *     Contact; evaluates every applicable CONTACT_CREATED automation.
 */
class AutomationJob extends Base
{
    public const MODE_DATE_SWEEP = 'date_sweep';
    public const MODE_CONTACT_CREATED = 'contact_created';

    private function __construct(
        private readonly string $mode,
        private readonly int $subjectId,
    ) {
        $this->onQueue('automation');
    }

    public static function forDateSweep(int $automationId): self
    {
        return new self(self::MODE_DATE_SWEEP, $automationId);
    }

    public static function forContactCreated(int $contactId): self
    {
        return new self(self::MODE_CONTACT_CREATED, $contactId);
    }

    public function handle(
        AutomationEligibility $eligibility,
        AutomationTriggerEvaluator $evaluator,
        AutomationExecutionClaimService $claims,
    ): void {
        match ($this->mode) {
            self::MODE_DATE_SWEEP => $this->runDateSweep($eligibility, $evaluator, $claims),
            self::MODE_CONTACT_CREATED => $this->runContactCreated($eligibility, $evaluator, $claims),
            default => throw new InvalidArgumentException('Unknown AutomationJob mode.'),
        };
    }

    private function runDateSweep(AutomationEligibility $eligibility, AutomationTriggerEvaluator $evaluator, AutomationExecutionClaimService $claims): void
    {
        // Checkpoint 1 (§9.1): fresh authoritative eligibility at
        // evaluation time — a NULL-business or disabled automation stops
        // here.
        $resolved = $eligibility->resolve($this->subjectId);

        if ($resolved === null || $resolved['automation']->trigger_type !== AutomationTriggerType::ContactDateReached) {
            return;
        }

        $automation = $resolved['automation'];
        $business = $resolved['business'];

        foreach ($evaluator->dueForDateReached($automation, $business, CarbonImmutable::now()) as $due) {
            /** @var Contacts $contact */
            $contact = $due['contact'];
            $key = AutomationExecutionClaimService::dateReachedKey($automation->id, $contact->id, $due['occurrence_year']);

            $this->claimAndDispatch($claims, $automation, $contact, AutomationTriggerType::ContactDateReached, $key);
        }
    }

    private function runContactCreated(AutomationEligibility $eligibility, AutomationTriggerEvaluator $evaluator, AutomationExecutionClaimService $claims): void
    {
        $contact = Contacts::query()->find($this->subjectId);

        // A NULL-business Contact (legacy, or created through the
        // out-of-scope DLR path) never triggers anything (§6.B).
        if ($contact === null || $contact->business_id === null) {
            return;
        }

        foreach ($evaluator->automationsForCreatedContact($contact) as $automation) {
            $key = AutomationExecutionClaimService::contactCreatedKey($automation->id, $contact->id);

            $this->claimAndDispatch($claims, $automation, $contact, AutomationTriggerType::ContactCreated, $key);
        }
    }

    private function claimAndDispatch(AutomationExecutionClaimService $claims, Automation $automation, Contacts $contact, AutomationTriggerType $trigger, string $key): void
    {
        // Checkpoint 2 (§9.1) lives inside claim(): a fresh re-read of the
        // automation immediately before the durable INSERT.
        $execution = $claims->claim($automation->id, $contact, $trigger, $key);

        if ($execution instanceof AutomationExecution) {
            SendAutomationMessage::dispatch($execution->id);
        }
    }
}
