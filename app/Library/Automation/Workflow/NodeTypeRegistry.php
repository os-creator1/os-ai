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

        return [...$errors, ...$this->validateTriggerSpecifics($triggerType, $config)];
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
            }

            $operator = ConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));

            if ($operator === null) {
                $errors[] = sprintf('Condition %d needs a comparison.', $position);

                continue;
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
