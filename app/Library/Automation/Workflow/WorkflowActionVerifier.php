<?php

namespace App\Library\Automation\Workflow;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Models\Business;

/**
 * Publish-time checks for the cross-domain actions: that every resource a step points
 * at is THIS Business's and still usable, that it fits the workflow's Location scope,
 * and that the account can actually execute what is configured.
 *
 * Shape is NodeTypeRegistry's (pure, no database). Tenancy and usability are answered
 * here against the ONE WorkflowReferenceCatalog the compiler already holds — a foreign
 * and a nonexistent id read the same: absent — and "can the account do this" is
 * WorkflowCapabilities' reading of the canonical entitlement and readiness authorities.
 * Nothing in this class decides an entitlement or a Location rule of its own.
 *
 * Every rule is re-proved at execution by the executor, because a pinned version
 * outlives whatever was true when it was published; refusing here tells the author at
 * publish instead of failing every journey later.
 *
 * LOCATION. A resource that belongs to ONE Location (a booking type) is refused when
 * a Location-limited workflow names none of that Location — every run would be
 * refused at run time. A workflow that covers it among others is allowed: the runs at
 * its Location work and the others are refused by the executor, never sent anyway.
 */
class WorkflowActionVerifier
{
    public function __construct(private readonly WorkflowCapabilities $capabilities)
    {
    }

    /**
     * @param list<array{key: string, type: WorkflowNodeType, config: array<string, mixed>, depth: int, edges: array<string, string>}> $flattened
     * @param \Closure(): WorkflowReferenceCatalog $references
     *
     * @return array<string, list<string>> problems keyed by node key
     */
    public function errors(
        Business $business,
        array $flattened,
        ?WorkflowTriggerType $trigger,
        WorkflowLocationScope $scope,
        \Closure $references,
    ): array {
        $errors = [];

        foreach ($flattened as $entry) {
            $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];

            $found = match ($entry['type']) {
                WorkflowNodeType::MoveOpportunity => $this->moveOpportunity($business, $config, $references),
                WorkflowNodeType::SendBookingLink => $this->bookingLink($business, $config, $scope, $references),
                WorkflowNodeType::SendForm => $this->formLink($business, $config, $references, false),
                WorkflowNodeType::SendQuestionnaire => $this->formLink($business, $config, $references, true),
                WorkflowNodeType::CreateSendProposal => $this->proposal($business, $config, $references),
                WorkflowNodeType::RequestPayment => $this->payment($business, $config, $trigger, $references),
                default => [],
            };

            foreach ($found as $message) {
                $errors[$entry['key']][] = $message;
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function moveOpportunity(Business $business, array $config, \Closure $references): array
    {
        if (! $this->capabilities->isAvailable($business, WorkflowCapabilities::CRM)) {
            return [$this->capabilities->reason($business, WorkflowCapabilities::CRM) ?? 'The sales pipeline is not available.'];
        }

        $catalog = $references();
        $pipeline = $catalog->pipeline((int) ($config['pipeline_id'] ?? 0));
        $stage = $catalog->stage((int) ($config['stage_id'] ?? 0));

        if ($pipeline === null) {
            return ['That pipeline does not belong to this business.'];
        }

        if ($pipeline['archived']) {
            return ['That pipeline is archived. Choose an active pipeline.'];
        }

        if ($stage === null) {
            return ['That stage does not belong to this business.'];
        }

        if ($stage['pipeline_id'] !== $pipeline['id']) {
            return ['That stage is not in the chosen pipeline.'];
        }

        return $stage['archived'] ? ['That stage is archived. Choose an active stage.'] : [];
    }

    /** @return list<string> */
    private function bookingLink(Business $business, array $config, WorkflowLocationScope $scope, \Closure $references): array
    {
        $problems = $this->channelProblems($business, $config);

        if (! $this->capabilities->isAvailable($business, WorkflowCapabilities::CALENDAR)) {
            return [$this->capabilities->reason($business, WorkflowCapabilities::CALENDAR) ?? 'The calendar is not available.', ...$problems];
        }

        $catalog = $references();
        $type = $catalog->bookingType((int) ($config['booking_type_id'] ?? 0));

        if ($type === null) {
            return ['That booking type does not belong to this business.', ...$problems];
        }

        if (! $type['active']) {
            $problems[] = 'That booking type is switched off. Choose an active one.';
        }

        $location = $catalog->location($type['location_id']);

        if ($location === null || ! $location['active']) {
            $problems[] = 'That booking type belongs to a location that is no longer active.';
        } elseif ($scope->isBound() && ! in_array($type['location_id'], $scope->ids(), true)) {
            $problems[] = 'That booking type belongs to ' . $location['name'] . ', which this workflow is not limited to. Choose a booking type of its locations.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function formLink(Business $business, array $config, \Closure $references, bool $questionnaire): array
    {
        $problems = $this->channelProblems($business, $config);

        if (! $this->capabilities->isAvailable($business, WorkflowCapabilities::FORMS)) {
            return [$this->capabilities->reason($business, WorkflowCapabilities::FORMS) ?? 'Forms are not available.', ...$problems];
        }

        $noun = $questionnaire ? 'questionnaire' : 'form';
        $form = $references()->form((int) ($config['form_id'] ?? 0));

        if ($form === null) {
            return ["That {$noun} does not belong to this business.", ...$problems];
        }

        if ($form['lifecycle'] !== 'active') {
            $problems[] = "That {$noun} is not switched on yet, so it has no public link.";
        }

        if (($form['pages'] >= 2) !== $questionnaire) {
            $problems[] = $questionnaire
                ? 'That is a one-page form. Choose a questionnaire (two or more pages), or use Send form.'
                : 'That is a questionnaire (two or more pages). Choose a one-page form, or use Send questionnaire.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function proposal(Business $business, array $config, \Closure $references): array
    {
        $problems = [];

        foreach ([WorkflowCapabilities::DOCUMENTS, WorkflowCapabilities::CATALOG] as $capability) {
            if (! $this->capabilities->isAvailable($business, $capability)) {
                $problems[] = $this->capabilities->reason($business, $capability) ?? 'Proposals are not available.';
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        return $this->catalogItemProblems($references, (int) ($config['catalog_item_id'] ?? 0));
    }

    /** @return list<string> */
    private function payment(Business $business, array $config, ?WorkflowTriggerType $trigger, \Closure $references): array
    {
        if (! $this->capabilities->isAvailable($business, WorkflowCapabilities::PAYMENTS)) {
            return [$this->capabilities->reason($business, WorkflowCapabilities::PAYMENTS) ?? 'Payments are not available.'];
        }

        if (($config['source'] ?? null) === 'document') {
            return $trigger !== null && $trigger->hasDocumentFact()
                ? []
                : ['Requesting payment for "this journey\'s document" only works when the workflow starts from a proposal, a document or a payment. Choose a new invoice instead, or change the trigger.'];
        }

        if (($config['catalog_item_id'] ?? null) === null) {
            return [];
        }

        if (! $this->capabilities->isAvailable($business, WorkflowCapabilities::CATALOG)) {
            return [$this->capabilities->reason($business, WorkflowCapabilities::CATALOG) ?? 'Products are not available.'];
        }

        return $this->catalogItemProblems($references, (int) $config['catalog_item_id']);
    }

    /** @return list<string> */
    private function catalogItemProblems(\Closure $references, int $itemId): array
    {
        $item = $references()->catalogItem($itemId);

        if ($item === null) {
            return ['That product or package does not belong to this business.'];
        }

        return $item['active'] ? [] : ['That product or package is archived. Choose an active one.'];
    }

    /**
     * The delivery channels a link action chose must be ones the account can use:
     * not "text me" for a Business with no number, not "email" with no mailbox.
     *
     * @return list<string>
     */
    private function channelProblems(Business $business, array $config): array
    {
        $problems = [];

        foreach ((array) ($config['channels'] ?? []) as $channel) {
            $capability = match ($channel) {
                'sms' => WorkflowCapabilities::SMS,
                'email' => WorkflowCapabilities::EMAIL,
                default => null,
            };

            if ($capability !== null && ! $this->capabilities->isAvailable($business, $capability)) {
                $problems[] = $this->capabilities->reason($business, $capability) ?? 'That way of sending is not available.';
            }
        }

        return $problems;
    }
}
