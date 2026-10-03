<?php

namespace App\Library\Automation\Workflow;

use App\Enums\Automation\Workflow\ConditionMatch;
use App\Enums\Automation\Workflow\ConditionOperator;
use App\Enums\Automation\Workflow\EnrollmentPolicy;
use App\Enums\Automation\Workflow\EnrollmentPolicySource;
use App\Enums\Automation\Workflow\FailurePolicy;
use App\Enums\Automation\Workflow\NodeSideEffectClass;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry;

/**
 * Automations V2 §5.2 — the single authority on what step types exist and what
 * shape each one's configuration must have.
 *
 * Nothing outside this class may decide that a node type is acceptable. A type
 * absent from WorkflowNodeType, or a config that does not satisfy the rules here,
 * is refused at validation, at compile and (by the advancer) at execution — so a
 * workflow can never reference a step the code cannot run.
 *
 * SHAPE ONLY. This class is pure: it never touches the database. Whether a
 * referenced contact group, custom field or channel actually belongs to the
 * workflow's Business is a tenancy question, and WorkflowCompiler answers it
 * against real rows. Keeping the two apart is what lets the whole of this class
 * be unit-tested without a schema, and what stops a shape check from quietly
 * becoming an authorization check.
 *
 * Executors are NOT registered here. V2-A and V2-B bind implementations of the
 * NodeExecutor contract for their own types; this slice only needs to know that a
 * type exists, how risky it is to re-run (NodeSideEffectClass), and what its
 * config must look like.
 */
class NodeTypeRegistry
{
    /** Upper bound on one SMS body, matching the legacy composer's own limit. */
    private const MAX_SMS_BODY_LENGTH = 1600;

    private const MAX_NOTIFICATION_LENGTH = 255;

    private const MAX_OPERAND_LENGTH = 255;

    /** The offsets a date trigger may use — B4's proven allowlist, unchanged. */
    public const DATE_OFFSET_ALLOWLIST = [
        '0 day', '1 day', '2 days', '3 days', '4 days', '5 days', '6 days',
        '1 week', '2 weeks', '1 month', '2 months',
    ];

    /** Where a `contact_created` trigger may require the contact to come from. */
    public const CONTACT_SOURCES = ['any', 'opt_in_form', 'in_app'];

    /** @return list<WorkflowNodeType> */
    public function all(): array
    {
        return WorkflowNodeType::cases();
    }

    public function isRegistered(string $type): bool
    {
        return WorkflowNodeType::tryFrom($type) !== null;
    }

    public function sideEffectClass(WorkflowNodeType $type): NodeSideEffectClass
    {
        return $type->sideEffectClass();
    }

    /**
     * Validate one node's configuration shape.
     *
     * @return list<string> human-readable problems; empty means the shape is fine
     */
    public function validateConfig(WorkflowNodeType $type, array $config): array
    {
        return match ($type) {
            WorkflowNodeType::Trigger => $this->validateTrigger($config),
            WorkflowNodeType::SendSms => $this->validateSendSms($config),
            WorkflowNodeType::UpdateContactField => $this->validateUpdateContactField($config),
            WorkflowNodeType::InternalNotification => $this->validateInternalNotification($config),
            WorkflowNodeType::SendEmail => $this->validateSendEmail($config),
            WorkflowNodeType::AddTag, WorkflowNodeType::RemoveTag => $this->validateTagAction($config),
            WorkflowNodeType::MoveOpportunity => $this->validateMoveOpportunity($config),
            WorkflowNodeType::SendBookingLink => $this->validateLinkAction($config, 'booking_type_id', 'Choose which booking type to send.'),
            WorkflowNodeType::SendForm => $this->validateLinkAction($config, 'form_id', 'Choose which form to send.'),
            WorkflowNodeType::SendQuestionnaire => $this->validateLinkAction($config, 'form_id', 'Choose which questionnaire to send.'),
            WorkflowNodeType::CreateSendProposal => $this->validateCreateSendProposal($config),
            WorkflowNodeType::RequestPayment => $this->validateRequestPayment($config),
            WorkflowNodeType::Wait => $this->validateWait($config),
            WorkflowNodeType::IfElse => $this->validateIfElse($config),
            WorkflowNodeType::End => $this->validateEnd($config),
        };
    }

    /**
     * The trigger node carries the workflow's whole behavioural contract: what
     * starts it, how often a contact may enter, and what a failure does.
     */
    private function validateTrigger(array $config): array
    {
        $errors = [];

        $triggerType = WorkflowTriggerType::tryFrom((string) ($config['trigger_type'] ?? ''));

        if ($triggerType === null) {
            return ['Choose what starts this workflow.'];
        }

        if (! $triggerType->isIngestableInThisSlice()) {
            $errors[] = sprintf(
                'The trigger "%s" cannot start a workflow yet, because nothing in the product reports it. Choose another trigger.',
                $triggerType->label(),
            );
        }

        $policy = EnrollmentPolicy::tryFrom((string) ($config['enrollment_policy'] ?? ''));
        $source = EnrollmentPolicySource::tryFrom((string) ($config['enrollment_policy_source'] ?? ''));

        if ($policy === null) {
            $errors[] = 'Choose how often a contact may enter this workflow.';
        }

        if ($source === null) {
            $errors[] = 'The enrollment rule is missing its origin.';
        }

        // THE STALE-DEFAULT RULE (§7.5). A policy that differs from the
        // trigger's default while still claiming to BE the default can only mean
        // one thing: the trigger was changed and the old default was carried
        // over. Publishing that is how a yearly birthday workflow silently turns
        // into a one-time one, so it is refused here rather than trusted to the
        // builder. A deliberate choice is always allowed — as `user`.
        if ($policy !== null && $source === EnrollmentPolicySource::Default) {
            $expected = $triggerType->defaultEnrollmentPolicy();

            if ($policy !== $expected) {
                $errors[] = sprintf(
                    'This trigger normally lets a contact enter "%s", but the workflow still carries "%s" from a previous trigger. Confirm which you want.',
                    $expected->label(),
                    $policy->label(),
                );
            }
        }

        if (FailurePolicy::tryFrom((string) ($config['failure_policy'] ?? '')) === null) {
            $errors[] = 'The failure rule is missing or not recognised.';
        }

        // Location scope, for every trigger: the whole Business (the default), one
        // Location, or a list of selected Locations. Shape only — whether each is an
        // active Location of THIS Business is the compiler's question, answered
        // against real rows.
        $errors = [...$errors, ...$this->validateLocationScope($config)];

        return [...$errors, ...$this->validateTriggerSpecifics($triggerType, $config)];
    }

    /**
     * The trigger's Location scope: `scope_mode` (business / one / selected), with
     * `business_location_id` for "one" and `business_location_ids` for "selected".
     * A document written before the mode existed carries only `business_location_id`,
     * which still means one Location (or none = Business-wide).
     *
     * @return list<string>
     */
    private function validateLocationScope(array $config): array
    {
        $mode = $config['scope_mode'] ?? null;
        $single = $config['business_location_id'] ?? null;
        $list = $config['business_location_ids'] ?? null;
        $hasSingle = $single !== null && $single !== '';

        if ($hasSingle && ! $this->isPositiveInt($single)) {
            return ['Choose a valid location, or leave it as the whole business.'];
        }

        if ($mode === null || $mode === '') {
            // The pre-mode shape. Stray list entries with no mode are ignored by the
            // reader, so they are refused here instead of being quietly dropped.
            return ($list === null || $list === []) ? [] : ['Choose how this workflow is limited: the whole business, one location, or selected locations.'];
        }

        if (! in_array($mode, WorkflowLocationScope::modes(), true)) {
            return ['Choose how this workflow is limited: the whole business, one location, or selected locations.'];
        }

        $ids = is_array($list) ? $list : [];

        foreach ($ids as $id) {
            if (! $this->isPositiveInt($id)) {
                return ['Choose valid locations, or limit the workflow some other way.'];
            }
        }

        return match ($mode) {
            WorkflowLocationScope::BUSINESS => ($hasSingle || $ids !== [])
                ? ['A whole-business workflow is not limited to any location. Clear the location, or choose one or more.']
                : [],
            WorkflowLocationScope::ONE => (! $hasSingle || $ids !== [])
                ? ['Choose the one location this workflow is limited to.']
                : [],
            WorkflowLocationScope::SELECTED => $this->selectedLocationErrors($hasSingle, $ids),
        };
    }

    /**
     * @param list<mixed> $ids
     * @return list<string>
     */
    private function selectedLocationErrors(bool $hasSingle, array $ids): array
    {
        if ($hasSingle) {
            return ['Selected locations use a list. Remove the single location, or choose "one location".'];
        }

        $unique = array_values(array_unique(array_map('intval', $ids)));

        if (count($unique) < 2) {
            return ['Choose two or more locations, or limit the workflow to one location.'];
        }

        if (count($unique) !== count($ids)) {
            return ['Each location can be chosen only once.'];
        }

        if (count($unique) > WorkflowLocationScope::MAX_SELECTED) {
            return [sprintf('Choose at most %d locations.', WorkflowLocationScope::MAX_SELECTED)];
        }

        return [];
    }

    private function validateTriggerSpecifics(WorkflowTriggerType $triggerType, array $config): array
    {
        $errors = [];

        if ($triggerType === WorkflowTriggerType::ContactCreated) {
            $source = (string) ($config['source'] ?? 'any');

            if (! in_array($source, self::CONTACT_SOURCES, true)) {
                $errors[] = 'That contact source is not one this product can tell apart.';
            }

            // contact_group_id is optional here: "any group" is valid.
            if (array_key_exists('contact_group_id', $config) && $config['contact_group_id'] !== null
                && ! $this->isPositiveInt($config['contact_group_id'])) {
                $errors[] = 'Choose a valid contact group, or leave it as any group.';
            }
        }

        // "Opportunity moves stage" may narrow to one pipeline, the stage a deal
        // leaves and/or the stage it enters. Each is optional ("any"), and must be
        // an id when present. Whether the ids belong to this Business is the
        // compiler's question, answered against the Business's CRM catalog.
        if ($triggerType === WorkflowTriggerType::OpportunityStageChanged) {
            $messages = [
                'pipeline_id' => 'Choose a valid pipeline, or leave it as any pipeline.',
                'from_stage_id' => 'Choose a valid stage to move from, or leave it as any stage.',
                'to_stage_id' => 'Choose a valid stage to move to, or leave it as any stage.',
            ];

            foreach ($messages as $key => $message) {
                if (array_key_exists($key, $config) && $config[$key] !== null && ! $this->isPositiveInt($config[$key])) {
                    $errors[] = $message;
                }
            }

            if ($this->isPositiveInt($config['from_stage_id'] ?? null) && $this->isPositiveInt($config['to_stage_id'] ?? null)
                && (int) $config['from_stage_id'] === (int) $config['to_stage_id']) {
                $errors[] = 'A deal cannot move from a stage to the same stage. Choose two different stages.';
            }
        }

        // Tag triggers may narrow to one tag ("any tag" when absent), and the form
        // trigger to one form. Each is optional and must be an id when present;
        // whether it belongs to this Business is the compiler's question.
        if ($triggerType->isContactTag() && array_key_exists('tag_id', $config) && $config['tag_id'] !== null && ! $this->isPositiveInt($config['tag_id'])) {
            $errors[] = 'Choose a valid tag, or leave it as any tag.';
        }

        if (in_array($triggerType, [WorkflowTriggerType::FormSubmitted, WorkflowTriggerType::QuestionnaireSubmitted], true)
            && array_key_exists('form_id', $config) && $config['form_id'] !== null && ! $this->isPositiveInt($config['form_id'])) {
            $errors[] = $triggerType === WorkflowTriggerType::QuestionnaireSubmitted
                ? 'Choose a valid questionnaire, or leave it as any questionnaire.'
                : 'Choose a valid form, or leave it as any form.';
        }

        // Document and payment triggers may narrow to one kind of document
        // (a proposal or an invoice). "Any" when absent.
        if ($triggerType->hasDocumentFact()
            && array_key_exists('document_kind', $config) && $config['document_kind'] !== null && $config['document_kind'] !== ''
            && ! in_array($config['document_kind'], self::DOCUMENT_KINDS, true)) {
            $errors[] = 'Choose proposals, invoices, or leave it as any document.';
        }

        if ($triggerType === WorkflowTriggerType::ContactDateReached) {
            if (! $this->isPositiveInt($config['contact_group_id'] ?? null)) {
                $errors[] = 'A date trigger needs one contact group to watch.';
            }

            if (! $this->isPositiveInt($config['date_field_id'] ?? null)) {
                $errors[] = 'Choose which date field to watch.';
            }

            if (! in_array((string) ($config['offset'] ?? ''), self::DATE_OFFSET_ALLOWLIST, true)) {
                $errors[] = 'Choose how far before or after the date to run.';
            }

            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($config['send_at'] ?? '')) !== 1) {
                $errors[] = 'Choose a time of day, as HH:MM.';
            }
        }

        return $errors;
    }

    private function validateSendSms(array $config): array
    {
        $body = $config['body'] ?? null;

        if (! is_string($body) || trim($body) === '') {
            return ['Write the message you want to send.'];
        }

        if (mb_strlen($body) > self::MAX_SMS_BODY_LENGTH) {
            return [sprintf('That message is too long. Keep it under %d characters.', self::MAX_SMS_BODY_LENGTH)];
        }

        return [];
    }

    private function validateSendEmail(array $config): array
    {
        $errors = [];
        $subject = $config['subject'] ?? null;
        $body = $config['body'] ?? null;

        $maxSubject = (int) config('business_email.send.max_subject_length', 200);
        $maxBody = (int) config('business_email.send.max_body_length', 20000);

        if (! is_string($subject) || trim($subject) === '') {
            $errors[] = 'Write the email subject.';
        } elseif (mb_strlen($subject) > $maxSubject) {
            $errors[] = sprintf('That subject is too long. Keep it under %d characters.', $maxSubject);
        }

        if (! is_string($body) || trim($body) === '') {
            $errors[] = 'Write the email you want to send.';
        } elseif (mb_strlen($body) > $maxBody) {
            $errors[] = sprintf('That email is too long. Keep it under %d characters.', $maxBody);
        }

        return $errors;
    }

    /** What a document trigger may narrow to; the values are DocumentKind's own. */
    public const DOCUMENT_KINDS = ['proposal', 'invoice'];

    /** How a link action may deliver. The booking, form and questionnaire links are plain public URLs, so both work. */
    public const LINK_CHANNELS = ['email', 'sms'];

    private const MAX_LINK_SUBJECT_LENGTH = 200;

    private const MAX_LINK_MESSAGE_LENGTH = 1000;

    private const MAX_DOCUMENT_TITLE_LENGTH = 200;

    private function validateMoveOpportunity(array $config): array
    {
        $errors = [];

        if (! $this->isPositiveInt($config['pipeline_id'] ?? null)) {
            $errors[] = 'Choose which pipeline the opportunity is in.';
        }

        if (! $this->isPositiveInt($config['stage_id'] ?? null)) {
            $errors[] = 'Choose which stage to move the opportunity to.';
        }

        return $errors;
    }

    /**
     * Booking-link, form and questionnaire actions: one resource, how to deliver
     * the link, and the words around it. The link itself is never in the config —
     * it is resolved from the resource when the step runs.
     */
    private function validateLinkAction(array $config, string $resourceKey, string $resourceMessage): array
    {
        $errors = [];

        if (! $this->isPositiveInt($config[$resourceKey] ?? null)) {
            $errors[] = $resourceMessage;
        }

        $errors = [...$errors, ...$this->validateChannels($config)];

        $subject = $config['subject'] ?? null;

        if ($subject !== null && $subject !== '' && (! is_string($subject) || mb_strlen($subject) > self::MAX_LINK_SUBJECT_LENGTH)) {
            $errors[] = sprintf('Keep the email subject under %d characters.', self::MAX_LINK_SUBJECT_LENGTH);
        }

        $message = $config['message'] ?? null;

        if ($message !== null && $message !== '' && (! is_string($message) || mb_strlen($message) > self::MAX_LINK_MESSAGE_LENGTH)) {
            $errors[] = sprintf('Keep the message under %d characters.', self::MAX_LINK_MESSAGE_LENGTH);
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateChannels(array $config): array
    {
        $channels = $config['channels'] ?? null;

        if (! is_array($channels) || $channels === []) {
            return ['Choose how to send it: email, text message, or both.'];
        }

        foreach ($channels as $channel) {
            if (! is_string($channel) || ! in_array($channel, self::LINK_CHANNELS, true)) {
                return ['Choose how to send it: email, text message, or both.'];
            }
        }

        return count(array_unique($channels)) === count($channels) ? [] : ['Choose each way of sending only once.'];
    }

    private function validateCreateSendProposal(array $config): array
    {
        $errors = [];
        $title = $config['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            $errors[] = 'Give the proposal a title.';
        } elseif (mb_strlen($title) > self::MAX_DOCUMENT_TITLE_LENGTH) {
            $errors[] = sprintf('Keep the title under %d characters.', self::MAX_DOCUMENT_TITLE_LENGTH);
        }

        if (! $this->isPositiveInt($config['catalog_item_id'] ?? null)) {
            $errors[] = 'Choose the product or package this proposal is for.';
        }

        $quantity = $config['quantity'] ?? 1;

        if (! $this->isPositiveInt($quantity) || (int) $quantity > 99) {
            $errors[] = 'Choose a quantity from 1 to 99.';
        }

        return [...$errors, ...$this->validateSchedule($config)];
    }

    /** The payment schedule a document may carry: pay in full, or a deposit then the balance. */
    private function validateSchedule(array $config): array
    {
        $schedule = $config['payment_schedule'] ?? 'full';

        if ($schedule === 'full') {
            return array_key_exists('deposit_percent', $config) && $config['deposit_percent'] !== null
                ? ['A deposit percentage only applies to a deposit-and-balance schedule.']
                : [];
        }

        if ($schedule !== 'deposit') {
            return ['Choose whether the customer pays in full, or a deposit first.'];
        }

        $percent = $config['deposit_percent'] ?? null;

        return $this->isPositiveInt($percent) && (int) $percent >= 5 && (int) $percent <= 95
            ? []
            : ['Choose a deposit between 5% and 95%.'];
    }

    /**
     * "Request payment" is NOT charging a saved card: it creates (or re-sends) the
     * canonical secure payment link. Either an invoice made here from a catalog item
     * or a fixed amount, or the link of the document the journey is about.
     */
    private function validateRequestPayment(array $config): array
    {
        $source = $config['source'] ?? null;

        if ($source === 'document') {
            return [];
        }

        if ($source !== 'invoice') {
            return ['Choose whether to request payment for this journey\'s document, or for a new invoice.'];
        }

        $errors = [];
        $title = $config['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            $errors[] = 'Give the invoice a title.';
        } elseif (mb_strlen($title) > self::MAX_DOCUMENT_TITLE_LENGTH) {
            $errors[] = sprintf('Keep the title under %d characters.', self::MAX_DOCUMENT_TITLE_LENGTH);
        }

        $hasItem = $this->isPositiveInt($config['catalog_item_id'] ?? null);
        $hasAmount = $this->isPositiveInt($config['amount_minor'] ?? null);

        if ($hasItem === $hasAmount) {
            $errors[] = 'Choose a product or package, or enter an amount — not both.';
        }

        if ($hasItem) {
            $quantity = $config['quantity'] ?? 1;

            if (! $this->isPositiveInt($quantity) || (int) $quantity > 99) {
                $errors[] = 'Choose a quantity from 1 to 99.';
            }
        }

        return $errors;
    }

    private function validateTagAction(array $config): array
    {
        return $this->isPositiveInt($config['tag_id'] ?? null) ? [] : ['Choose a tag.'];
    }

    private function validateUpdateContactField(array $config): array
    {
        $errors = [];

        if (! $this->isPositiveInt($config['field_id'] ?? null)) {
            $errors[] = 'Choose which contact field to update.';
        }

        $value = $config['value'] ?? null;

        if (! is_string($value)) {
            $errors[] = 'Give the value to write.';
        } elseif (mb_strlen($value) > self::MAX_OPERAND_LENGTH) {
            $errors[] = sprintf('That value is too long. Keep it under %d characters.', self::MAX_OPERAND_LENGTH);
        }

        return $errors;
    }

    private function validateInternalNotification(array $config): array
    {
        $message = $config['message'] ?? null;

        if (! is_string($message) || trim($message) === '') {
            return ['Write what the team should be told.'];
        }

        if (mb_strlen($message) > self::MAX_NOTIFICATION_LENGTH) {
            return [sprintf('Keep the note under %d characters.', self::MAX_NOTIFICATION_LENGTH)];
        }

        return [];
    }

    private function validateWait(array $config): array
    {
        $mode = (string) ($config['mode'] ?? '');

        if ($mode === 'duration') {
            $amount = $config['amount'] ?? null;
            $unit = (string) ($config['unit'] ?? '');

            if (! in_array($unit, ['minutes', 'hours', 'days'], true)) {
                return ['Choose whether to wait minutes, hours or days.'];
            }

            if (! $this->isPositiveInt($amount)) {
                return ['Say how long to wait.'];
            }

            $minutes = match ($unit) {
                'minutes' => (int) $amount,
                'hours' => (int) $amount * 60,
                'days' => (int) $amount * 1440,
            };

            if ($minutes < WorkflowLimits::MIN_WAIT_MINUTES) {
                return [sprintf('The shortest wait is %d minute.', WorkflowLimits::MIN_WAIT_MINUTES)];
            }

            if ($minutes > WorkflowLimits::MAX_WAIT_DAYS * 1440) {
                return [sprintf('The longest wait is %d days.', WorkflowLimits::MAX_WAIT_DAYS)];
            }

            return [];
        }

        if ($mode === 'until_datetime') {
            $at = (string) ($config['at'] ?? '');

            if (preg_match('/^\d{4}-\d{2}-\d{2}[ T][0-2]\d:[0-5]\d$/', $at) !== 1) {
                return ['Choose the date and time to wait until.'];
            }

            return [];
        }

        return ['Choose whether to wait a length of time or until a specific moment.'];
    }

    private function validateIfElse(array $config): array
    {
        $errors = [];

        if (ConditionMatch::tryFrom((string) ($config['match'] ?? '')) === null) {
            $errors[] = 'Choose whether all or any of the conditions must be true.';
        }

        $conditions = $config['conditions'] ?? null;

        if (! is_array($conditions) || $conditions === []) {
            return [...$errors, 'Add at least one condition.'];
        }

        if (count($conditions) > WorkflowLimits::MAX_CONDITIONS_PER_BRANCH) {
            $errors[] = sprintf(
                'Use at most %d conditions here. For more, add another If / Else step inside this one.',
                WorkflowLimits::MAX_CONDITIONS_PER_BRANCH,
            );
        }

        foreach (array_values($conditions) as $index => $condition) {
            $position = $index + 1;

            if (! is_array($condition)) {
                $errors[] = sprintf('Condition %d is not readable.', $position);

                continue;
            }

            $subject = $condition['subject'] ?? null;

            if (! is_string($subject) || trim($subject) === '') {
                $errors[] = sprintf('Condition %d needs something to check.', $position);
            } elseif (! ConditionSubjectRegistry::isKnownSubjectKey($subject)) {
                // V2 logic runtime — the subject must be one the code knows.
                // Refusing here is what makes "no expression language" true at
                // the door rather than only at execution: an unknown key cannot
                // be published, so nothing downstream has to guess what it meant.
                $errors[] = sprintf('Condition %d checks something this product cannot read.', $position);
            }

            $operator = ConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));

            if ($operator === null) {
                $errors[] = sprintf('Condition %d needs a comparison.', $position);

                continue;
            }

            // Operator legality, for every subject whose family is knowable
            // without a query. A custom field's family depends on the field's
            // own type, so WorkflowCompiler decides that one against real rows —
            // this class stays pure.
            if (is_string($subject)) {
                $allowed = ConditionSubjectRegistry::staticOperatorsFor($subject);

                if ($allowed !== null && ! in_array($operator, $allowed, true)) {
                    $errors[] = sprintf(
                        'Condition %d uses a comparison that does not apply to what it checks.',
                        $position,
                    );
                }
            }

            $hasOperand = array_key_exists('operand', $condition)
                && $condition['operand'] !== null
                && $condition['operand'] !== '';

            if ($operator->requiresOperand() && ! $hasOperand) {
                $errors[] = sprintf('Condition %d needs a value to compare against.', $position);
            }

            if (! $operator->requiresOperand() && $hasOperand) {
                $errors[] = sprintf('Condition %d does not take a value.', $position);
            }

            if ($hasOperand && is_string($condition['operand'])
                && mb_strlen($condition['operand']) > self::MAX_OPERAND_LENGTH) {
                $errors[] = sprintf('Condition %d\'s value is too long.', $position);
            }

            // The fact-backed subjects take a closed set of values (or a stage id).
            if (is_string($subject) && $hasOperand && ($problem = ConditionSubjectRegistry::operandProblem($subject, $condition['operand'])) !== null) {
                $errors[] = sprintf('Condition %d %s.', $position, $problem);
            }
        }

        return $errors;
    }

    private function validateEnd(array $config): array
    {
        // An End step is deliberately configuration-free. Anything in it is a
        // sign the builder sent the wrong node type.
        return $config === [] ? [] : ['An End step takes no settings.'];
    }

    private function isPositiveInt(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0;
    }
}
