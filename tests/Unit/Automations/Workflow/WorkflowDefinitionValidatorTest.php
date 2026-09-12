<?php

namespace Tests\Unit\Automations\Workflow;

use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\WorkflowDefinitionValidator;
use App\Library\Automation\Workflow\WorkflowLimits;
use PHPUnit\Framework\TestCase;

/**
 * Automations V2 V2-0 — T-WF-3 and T-WF-34's validator half.
 *
 * The validator is pure, so this is a true unit test: no database, no container,
 * no fixtures. That is worth having, because these are the rules that decide
 * whether a customer's workflow is allowed to exist, and they should be provable
 * in milliseconds.
 */
class WorkflowDefinitionValidatorTest extends TestCase
{
    private WorkflowDefinitionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new WorkflowDefinitionValidator(new NodeTypeRegistry());
    }

    private function trigger(array $overrides = [], array $next = []): array
    {
        return [
            'schema_version' => 1,
            'root' => [
                'key' => 'n-trigger',
                'type' => 'trigger',
                'config' => array_merge([
                    'trigger_type' => 'contact_created',
                    'enrollment_policy' => 'once_ever',
                    'enrollment_policy_source' => 'default',
                    'failure_policy' => 'halt',
                ], $overrides),
                'next' => $next,
            ],
        ];
    }

    private function sms(string $key, string $body = 'Hi'): array
    {
        return ['key' => $key, 'type' => 'send_sms', 'config' => ['body' => $body]];
    }

    private function assertValid(array $definition): void
    {
        $this->assertSame([], $this->validator->validate($definition));
    }

    private function assertInvalid(array $definition, ?string $contains = null): array
    {
        $errors = $this->validator->validate($definition);
        $this->assertNotEmpty($errors, 'Expected this document to be refused.');

        if ($contains !== null) {
            $flat = [];
            array_walk_recursive($errors, static function ($m) use (&$flat): void { $flat[] = $m; });
            $this->assertStringContainsString($contains, implode(' ', $flat));
        }

        return $errors;
    }

    public function test_a_minimal_trigger_only_workflow_is_valid(): void
    {
        $this->assertValid($this->trigger());
    }

    public function test_a_document_needs_the_right_schema_version(): void
    {
        $definition = $this->trigger();
        $definition['schema_version'] = 99;

        $this->assertInvalid($definition, 'different version of the editor');
    }

    public function test_a_workflow_must_start_with_a_trigger(): void
    {
        $this->assertInvalid([
            'schema_version' => 1,
            'root' => ['key' => 'n1', 'type' => 'send_sms', 'config' => ['body' => 'Hi']],
        ], 'must start with a trigger');
    }

    public function test_a_second_trigger_anywhere_else_is_refused(): void
    {
        $this->assertInvalid(
            $this->trigger([], [[
                'key' => 'n2',
                'type' => 'trigger',
                'config' => ['trigger_type' => 'contact_created'],
            ]]),
            'only have one trigger',
        );
    }

    public function test_an_unknown_step_type_is_refused(): void
    {
        $this->assertInvalid(
            $this->trigger([], [['key' => 'n2', 'type' => 'summon_intern', 'config' => []]]),
            'not one this product can run',
        );
    }

    public function test_a_duplicate_step_key_is_refused(): void
    {
        $this->assertInvalid(
            $this->trigger([], [$this->sms('dup'), $this->sms('dup')]),
            'appears twice',
        );
    }

    public function test_nothing_may_follow_an_if_else(): void
    {
        $this->assertInvalid($this->trigger([], [[
            'key' => 'branch',
            'type' => 'if_else',
            'config' => ['match' => 'all', 'conditions' => [['subject' => 'contact.subscribed', 'operator' => 'is_true']]],
            'yes' => [],
            'no' => [],
            'next' => [$this->sms('after')],
        ]]), 'cannot continue after an If / Else');
    }

    public function test_a_step_after_an_if_else_in_the_same_sequence_is_refused(): void
    {
        $this->assertInvalid($this->trigger([], [
            [
                'key' => 'branch',
                'type' => 'if_else',
                'config' => ['match' => 'any', 'conditions' => [['subject' => 'contact.subscribed', 'operator' => 'is_true']]],
                'yes' => [],
                'no' => [],
            ],
            $this->sms('unreachable'),
        ]), 'must be the last step in its path');
    }

    public function test_nothing_may_follow_an_end(): void
    {
        $this->assertInvalid($this->trigger([], [
            ['key' => 'stop', 'type' => 'end', 'config' => []],
            $this->sms('after-end'),
        ]), 'Nothing can follow an End step');
    }

    public function test_only_an_if_else_may_have_lanes(): void
    {
        $this->assertInvalid($this->trigger([], [[
            'key' => 'n2',
            'type' => 'send_sms',
            'config' => ['body' => 'Hi'],
            'yes' => [$this->sms('n3')],
        ]]), 'Only an If / Else step can have');
    }

    public function test_the_node_limit_is_enforced(): void
    {
        $steps = [];

        for ($i = 0; $i <= WorkflowLimits::MAX_NODES_PER_VERSION; $i++) {
            $steps[] = $this->sms('n' . $i);
        }

        $this->assertInvalid($this->trigger([], $steps), 'The limit is ' . WorkflowLimits::MAX_NODES_PER_VERSION);
    }

    public function test_branch_depth_is_enforced(): void
    {
        $branch = static function (array $inner): array {
            static $n = 0;

            return [
                'key' => 'b' . $n++,
                'type' => 'if_else',
                'config' => ['match' => 'all', 'conditions' => [['subject' => 'contact.subscribed', 'operator' => 'is_true']]],
                'yes' => $inner,
                'no' => [],
            ];
        };

        $nested = [$branch([])];

        for ($depth = 0; $depth <= WorkflowLimits::MAX_BRANCH_DEPTH; $depth++) {
            $nested = [$branch($nested)];
        }

        $this->assertInvalid($this->trigger([], $nested), 'branches deep');
    }

    public function test_too_many_conditions_are_refused(): void
    {
        $conditions = array_fill(
            0,
            WorkflowLimits::MAX_CONDITIONS_PER_BRANCH + 1,
            ['subject' => 'contact.subscribed', 'operator' => 'is_true'],
        );

        $this->assertInvalid($this->trigger([], [[
            'key' => 'branch',
            'type' => 'if_else',
            'config' => ['match' => 'all', 'conditions' => $conditions],
            'yes' => [],
            'no' => [],
        ]]), 'at most ' . WorkflowLimits::MAX_CONDITIONS_PER_BRANCH . ' conditions');
    }

    public function test_an_operator_that_takes_no_value_is_refused_with_one(): void
    {
        $this->assertInvalid($this->trigger([], [[
            'key' => 'branch',
            'type' => 'if_else',
            'config' => ['match' => 'all', 'conditions' => [
                ['subject' => 'contact.subscribed', 'operator' => 'is_true', 'operand' => 'yes'],
            ]],
            'yes' => [],
            'no' => [],
        ]]), 'does not take a value');
    }

    public function test_an_operator_that_needs_a_value_is_refused_without_one(): void
    {
        $this->assertInvalid($this->trigger([], [[
            'key' => 'branch',
            'type' => 'if_else',
            'config' => ['match' => 'all', 'conditions' => [
                ['subject' => 'contact.first_name', 'operator' => 'equals'],
            ]],
            'yes' => [],
            'no' => [],
        ]]), 'needs a value to compare');
    }

    /** T-WF-34 — the stale-default rule, the reason D3 changed. */
    public function test_a_default_policy_that_does_not_match_its_trigger_is_refused(): void
    {
        $this->assertInvalid($this->trigger([
            'trigger_type' => 'contact_date_reached',
            'contact_group_id' => 4,
            'date_field_id' => 7,
            'offset' => '0 day',
            'send_at' => '09:00',
            'enrollment_policy' => 'once_ever',
            'enrollment_policy_source' => 'default',
        ]), 'previous trigger');
    }

    /** The same policy is fine when the customer chose it deliberately. */
    public function test_a_user_chosen_policy_may_differ_from_the_trigger_default(): void
    {
        $this->assertValid($this->trigger([
            'trigger_type' => 'contact_date_reached',
            'contact_group_id' => 4,
            'date_field_id' => 7,
            'offset' => '0 day',
            'send_at' => '09:00',
            'enrollment_policy' => 'once_ever',
            'enrollment_policy_source' => 'user',
        ]));
    }

    /** A trigger with no producer yet cannot be published. */
    public function test_a_trigger_with_no_producer_is_refused(): void
    {
        $this->assertInvalid($this->trigger([
            'trigger_type' => 'message_received',
            'enrollment_policy' => 'once_per_occurrence',
        ]), 'cannot start a workflow yet');
    }

    public function test_a_date_trigger_needs_its_group_field_offset_and_time(): void
    {
        $errors = $this->assertInvalid($this->trigger([
            'trigger_type' => 'contact_date_reached',
            'enrollment_policy' => 'once_per_occurrence',
        ]));

        $messages = implode(' ', $errors['n-trigger']);
        $this->assertStringContainsString('one contact group to watch', $messages);
        $this->assertStringContainsString('which date field', $messages);
        $this->assertStringContainsString('how far before or after', $messages);
        $this->assertStringContainsString('time of day', $messages);
    }

    public function test_wait_bounds_are_enforced(): void
    {
        $tooLong = $this->trigger([], [[
            'key' => 'w',
            'type' => 'wait',
            'config' => ['mode' => 'duration', 'amount' => WorkflowLimits::MAX_WAIT_DAYS + 1, 'unit' => 'days'],
        ]]);

        $this->assertInvalid($tooLong, 'longest wait is');

        $this->assertValid($this->trigger([], [[
            'key' => 'w',
            'type' => 'wait',
            'config' => ['mode' => 'duration', 'amount' => 1, 'unit' => 'minutes'],
        ]]));

        $this->assertValid($this->trigger([], [[
            'key' => 'w',
            'type' => 'wait',
            'config' => ['mode' => 'until_datetime', 'at' => '2027-01-01 09:00'],
        ]]));
    }

    public function test_an_empty_message_is_refused(): void
    {
        $this->assertInvalid($this->trigger([], [$this->sms('n2', '   ')]), 'Write the message');
    }

    public function test_an_oversized_document_is_refused(): void
    {
        $this->assertInvalid(
            $this->trigger([], [$this->sms('n2', str_repeat('x', WorkflowLimits::MAX_DEFINITION_BYTES))]),
            'too large to save',
        );
    }
}
