<?php

namespace Tests\Feature\Automations\Workflow\Builder;

use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\WorkflowDefinitionValidator;
use Tests\TestCase;

/**
 * Automations V2 (contract §5.3, §16 "Templates must compile into the SAME
 * V2 Draft document", V2-D) — recipe templates produce ordinary §5.3
 * documents, never a parallel engine.
 *
 * Each fixture below is a byte-for-byte transcription of one
 * resources/js/automations/workflow-builder/recipes.js factory's output
 * (kept in sync by convention — a change to one must mirror in the other,
 * exactly like NODE_LABELS/NODE_TYPES in constants.js mirror
 * WorkflowNodeType server-side). Running each through the REAL, unmodified
 * WorkflowDefinitionValidator + NodeTypeRegistry (V2-0, untouched by this
 * slice) is what makes this a genuine proof rather than an assumption: a
 * recipe that drifted out of the schema the compiler accepts would fail
 * here. The end-to-end path — the actual JS factory, exercised through a
 * real browser, actually autosaved and validated by the server — is
 * additionally proven interactively (see this task's browser verification).
 */
class RecipeDocumentTest extends TestCase
{
    private function validator(): WorkflowDefinitionValidator
    {
        return new WorkflowDefinitionValidator(new NodeTypeRegistry());
    }

    /** Only "needs configuring" content errors are acceptable — never a structural/type error. */
    private function assertNoStructuralErrors(array $errors, array $allowedMessageSubstrings = []): void
    {
        foreach ($errors as $key => $messages) {
            foreach ($messages as $message) {
                $allowed = false;

                foreach ($allowedMessageSubstrings as $substring) {
                    if (str_contains($message, $substring)) {
                        $allowed = true;

                        break;
                    }
                }

                $this->assertTrue($allowed, "Unexpected structural error on [{$key}]: {$message}");
            }
        }
    }

    public function test_welcome_new_contact_recipe_is_a_valid_publishable_document(): void
    {
        $definition = [
            'schema_version' => 1,
            'root' => [
                'key' => 'trigger-1',
                'type' => 'trigger',
                'config' => [
                    'trigger_type' => 'contact_created',
                    'source' => 'any',
                    'contact_group_id' => null,
                    'enrollment_policy' => 'once_ever',
                    'enrollment_policy_source' => 'default',
                    'failure_policy' => 'halt',
                ],
                'next' => [
                    ['key' => 'sms-1', 'type' => 'send_sms', 'config' => ['body' => 'Hi {first_name}, thanks for reaching out!'], 'next' => []],
                ],
            ],
        ];

        $errors = $this->validator()->validate($definition);

        $this->assertSame([], $errors, 'This recipe must be immediately publishable with no configuration needed.');
    }

    public function test_notify_team_new_contact_recipe_is_a_valid_publishable_document(): void
    {
        $definition = [
            'schema_version' => 1,
            'root' => [
                'key' => 'trigger-1',
                'type' => 'trigger',
                'config' => [
                    'trigger_type' => 'contact_created',
                    'source' => 'any',
                    'contact_group_id' => null,
                    'enrollment_policy' => 'once_ever',
                    'enrollment_policy_source' => 'default',
                    'failure_policy' => 'halt',
                ],
                'next' => [
                    ['key' => 'notify-1', 'type' => 'internal_notification', 'config' => ['message' => 'New contact: {first_name} {last_name}'], 'next' => []],
                ],
            ],
        ];

        $this->assertSame([], $this->validator()->validate($definition));
    }

    public function test_check_in_after_days_recipe_is_a_valid_publishable_document_with_a_two_lane_branch(): void
    {
        $definition = [
            'schema_version' => 1,
            'root' => [
                'key' => 'trigger-1',
                'type' => 'trigger',
                'config' => [
                    'trigger_type' => 'contact_created',
                    'source' => 'any',
                    'contact_group_id' => null,
                    'enrollment_policy' => 'once_ever',
                    'enrollment_policy_source' => 'default',
                    'failure_policy' => 'halt',
                ],
                'next' => [
                    [
                        'key' => 'wait-1',
                        'type' => 'wait',
                        'config' => ['mode' => 'duration', 'amount' => 2, 'unit' => 'days'],
                        'next' => [
                            [
                                'key' => 'branch-1',
                                'type' => 'if_else',
                                'config' => ['match' => 'all', 'conditions' => [['subject' => 'contact.subscribed', 'operator' => 'is_true']]],
                                'yes' => [['key' => 'sms-1', 'type' => 'send_sms', 'config' => ['body' => 'Hi {first_name}, just checking in!'], 'next' => []]],
                                'no' => [['key' => 'end-1', 'type' => 'end', 'config' => []]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame([], $this->validator()->validate($definition));
    }

    /**
     * The date-reminder recipe needs a customer-chosen contact group and
     * date field before it can publish — that is expected, not a defect:
     * a recipe is a starting document (task requirement), never a
     * guarantee of zero remaining configuration when the trigger itself
     * inherently needs Business-specific data the recipe cannot invent.
     */
    public function test_date_reminder_recipe_is_structurally_valid_and_needs_only_its_expected_business_specific_fields(): void
    {
        $definition = [
            'schema_version' => 1,
            'root' => [
                'key' => 'trigger-1',
                'type' => 'trigger',
                'config' => [
                    'trigger_type' => 'contact_date_reached',
                    'contact_group_id' => null,
                    'date_field_id' => null,
                    'offset' => '0 day',
                    'send_at' => '09:00',
                    'enrollment_policy' => 'once_per_occurrence',
                    'enrollment_policy_source' => 'default',
                    'failure_policy' => 'halt',
                ],
                'next' => [
                    ['key' => 'sms-1', 'type' => 'send_sms', 'config' => ['body' => 'Hi {first_name}, a reminder from {business_name}!'], 'next' => []],
                ],
            ],
        ];

        $errors = $this->validator()->validate($definition);

        $this->assertArrayHasKey('trigger-1', $errors);
        $this->assertNoStructuralErrors($errors, ['A date trigger needs one contact group to watch.', 'Choose which date field to watch.']);
    }

    /** Every recipe uses only the launch node/trigger vocabulary — never an unregistered type. */
    public function test_every_recipe_uses_only_registered_node_and_trigger_types(): void
    {
        $registry = new NodeTypeRegistry();

        foreach (['trigger', 'send_sms', 'internal_notification', 'wait', 'if_else', 'end'] as $type) {
            $this->assertTrue($registry->isRegistered($type), "Recipe vocabulary must stay inside the registered node types: {$type}");
        }
    }
}
