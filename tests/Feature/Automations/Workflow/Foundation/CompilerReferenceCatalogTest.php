<?php

namespace Tests\Feature\Automations\Workflow\Foundation;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\WorkflowCompiler;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowReferenceCatalog;
use App\Library\Automation\Workflow\WorkflowReferenceCatalogLoader;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Logic\Support\BuildsLogicWorkflows;
use Tests\TestCase;

/**
 * Automations V2 §14.2 — WorkflowCompiler's reference checks, answered from ONE
 * WorkflowReferenceCatalog read.
 *
 * Two families of proof. COST: however many steps, conditions or repeated ids a
 * document holds, validating its references reads the Business's groups and
 * fields at most once — and not at all when it references nothing, or when the
 * caller (the Builder) already holds the catalog. CORRECTNESS: every tenancy
 * and operator-family refusal the per-reference queries made is still made,
 * with the same wording, keyed to the same step.
 *
 * Validation is called on the draft version with its document set in memory,
 * so only the compiler's own statements are counted: no autosave, no publish.
 */
class CompilerReferenceCatalogTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsLogicWorkflows;

    private const GROUP_FOREIGN = 'That contact group does not belong to this business.';
    private const DATE_FIELD_OUTSIDE = 'That date field does not belong to the contact group this workflow watches.';
    private const FIELD_OUTSIDE = 'That field does not belong to the contact group this workflow watches.';
    private const FIELD_NEEDS_GROUP = 'To update a contact field, the trigger must watch one specific contact group.';

    // =================================================================
    // Cost — constant, whatever the document holds
    // =================================================================

    public function test_a_document_that_references_nothing_reads_nothing(): void
    {
        [, $business] = $this->entitledTenant();
        $version = $this->version($business, $this->document(null, [$this->smsStep()]));

        [$errors, $queries] = $this->validateCounting($version);

        $this->assertSame([], $errors);
        $this->assertSame([], $queries, 'No group or field is referenced, so nothing is read.');
    }

    public function test_one_field_reference_is_one_catalog_read(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Watched');
        $field = $this->textField($group, 'NOTE_1');

        $version = $this->version($business, $this->document((int) $group->id, [$this->updateStep((int) $field->id)]));

        [$errors, $queries] = $this->validateCounting($version);

        $this->assertSame([], $errors);
        $this->assertOneCatalogRead($queries);
    }

    public function test_ten_field_references_are_still_one_catalog_read(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Watched');
        $steps = [];

        foreach (range(1, 10) as $i) {
            $steps[] = $this->updateStep((int) $this->textField($group, 'NOTE_' . $i)->id);
        }

        [$errors, $queries] = $this->validateCounting($this->version($business, $this->document((int) $group->id, $steps)));

        $this->assertSame([], $errors);
        $this->assertOneCatalogRead($queries);
    }

    /**
     * Fifty distinct references across every kind the compiler checks: the
     * trigger group, 19 field updates, 15 in-group conditions and 15
     * custom-field conditions spread over six nested If/Else steps. Valid, one
     * read; and the same document pointed entirely at another Business is
     * refused everywhere — still one read.
     */
    public function test_fifty_group_and_field_references_are_still_one_catalog_read_valid_or_foreign(): void
    {
        [, $business] = $this->entitledTenant();
        [, $stranger] = $this->entitledTenant();

        foreach ([[$business, true], [$stranger, false]] as [$owner, $valid]) {
            [$definition, $referenceCount] = $this->fiftyReferenceDocument($owner);
            $this->assertSame(50, $referenceCount, 'Precondition: fifty distinct references.');

            [$errors, $queries] = $this->validateCounting($this->version($business, $definition));

            $this->assertOneCatalogRead($queries);

            if ($valid) {
                $this->assertSame([], $errors, 'Every reference belongs to this Business.');
            } else {
                $messages = collect($errors)->flatten();
                $this->assertContains(self::GROUP_FOREIGN, $messages->all());
                $this->assertSame(19, $messages->filter(fn (string $m) => $m === self::FIELD_OUTSIDE)->count());
                $this->assertSame(15, $messages->filter(fn (string $m) => str_ends_with($m, 'checks a contact group that does not belong to this business.'))->count());
                $this->assertSame(15, $messages->filter(fn (string $m) => str_ends_with($m, 'checks a contact field that does not belong to this business.'))->count());
            }
        }
    }

    /** The same field twenty times and the same group five times: still one read, never a repeated statement. */
    public function test_duplicated_references_are_read_once(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Watched');
        $field = $this->textField($group, 'NOTE');

        $steps = array_map(fn () => $this->updateStep((int) $field->id), range(1, 20));
        $steps[] = $this->ifElseStep(array_merge(
            array_map(fn () => $this->condition('contact.in_group', 'equals', (string) $group->id), range(1, 3)),
            array_map(fn () => $this->condition('contact.custom_field:' . $field->id, 'equals', 'x'), range(1, 2)),
        ));

        [$errors, $queries] = $this->validateCounting($this->version($business, $this->document((int) $group->id, $steps)));

        $this->assertSame([], $errors);
        $this->assertOneCatalogRead($queries);
        $this->assertSame(count($queries), count(array_unique($queries)), 'No statement is ever repeated.');
    }

    /**
     * The reuse seam: the Builder loads the catalog for its pickers and hands the
     * same object to the compiler, so the request pays for one read, not two —
     * and the answer is identical to the compiler loading it itself.
     */
    public function test_a_catalog_the_caller_already_holds_costs_the_compiler_nothing(): void
    {
        [, $business] = $this->entitledTenant();
        [, $stranger] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Watched');
        $field = $this->textField($group, 'NOTE');
        $foreignField = $this->textField($this->contactGroup($stranger, 'Theirs'), 'THEIRS');

        $version = $this->version($business, $this->document((int) $group->id, [
            $this->updateStep((int) $field->id),
            $this->updateStep((int) $foreignField->id),
        ]));

        [$catalog, $loadQueries] = $this->counting(fn () => app(WorkflowReferenceCatalogLoader::class)->forBusiness($business));
        $this->assertOneCatalogRead($loadQueries);

        [$withCatalog, $queries] = $this->counting(fn () => app(WorkflowCompiler::class)->validate($version, $catalog));

        $this->assertSame([], $queries, 'A supplied catalog is not read again.');
        $this->assertSame(app(WorkflowCompiler::class)->validate($version), $withCatalog, 'Same answer either way.');
        $this->assertSame([self::FIELD_OUTSIDE], array_values($withCatalog)[0]);
    }

    public function test_a_catalog_of_another_business_is_refused_as_a_programming_error(): void
    {
        [, $business] = $this->entitledTenant();
        [, $stranger] = $this->entitledTenant();
        $version = $this->version($business, $this->document(null, [$this->smsStep()]));

        $this->expectException(\InvalidArgumentException::class);

        app(WorkflowCompiler::class)->validate($version, app(WorkflowReferenceCatalogLoader::class)->forBusiness($stranger));
    }

    // =================================================================
    // Correctness — the same refusals, the same wording
    // =================================================================

    public function test_a_trigger_group_of_this_business_is_accepted_and_a_foreign_one_is_refused_on_the_trigger(): void
    {
        [, $business] = $this->entitledTenant();
        [, $stranger] = $this->entitledTenant();
        $own = $this->contactGroup($business, 'Ours');
        $foreign = $this->contactGroup($stranger, 'Theirs');

        $this->assertSame([], $this->validate($business, $this->document((int) $own->id, [$this->smsStep()])));

        $definition = $this->document((int) $foreign->id, [$this->smsStep()]);
        $errors = $this->validate($business, $definition);

        $this->assertSame([$definition['root']['key'] => [self::GROUP_FOREIGN]], $errors);

        // An id that exists nowhere reads exactly like a foreign one.
        $this->assertSame(
            [self::GROUP_FOREIGN],
            array_values($this->validate($business, $this->document(99999999, [$this->smsStep()])))[0],
        );
    }

    public function test_a_date_field_of_the_watched_group_is_accepted(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Ours');
        $field = $this->dateField($group, 'BIRTH_DATE');

        $this->assertSame([], $this->validate($business, $this->dateDocument((int) $group->id, (int) $field->id), WorkflowTriggerType::ContactDateReached));
    }

    public function test_a_date_field_outside_the_watched_group_is_refused_whether_same_business_or_foreign(): void
    {
        [, $business] = $this->entitledTenant();
        [, $stranger] = $this->entitledTenant();
        $watched = $this->contactGroup($business, 'Watched');
        $sibling = $this->dateField($this->contactGroup($business, 'Other'), 'OTHER_DATE');
        $foreign = $this->dateField($this->contactGroup($stranger, 'Theirs'), 'THEIR_DATE');

        // Same Business but another group, another Business, and an id that exists nowhere.
        foreach ([(int) $sibling->id, (int) $foreign->id, 99999999] as $fieldId) {
            $definition = $this->dateDocument((int) $watched->id, $fieldId);

            $this->assertSame(
                [$definition['root']['key'] => [self::DATE_FIELD_OUTSIDE]],
                $this->validate($business, $definition, WorkflowTriggerType::ContactDateReached),
            );
        }
    }

    public function test_an_update_contact_field_in_the_watched_group_is_accepted(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Watched');
        $field = $this->textField($group, 'NOTE');

        $this->assertSame([], $this->validate($business, $this->document((int) $group->id, [$this->updateStep((int) $field->id)])));
    }

    public function test_an_update_contact_field_from_another_group_or_business_is_refused_on_its_own_step(): void
    {
        [, $business] = $this->entitledTenant();
        [, $stranger] = $this->entitledTenant();
        $watched = $this->contactGroup($business, 'Watched');
        $own = $this->textField($watched, 'OWN');
        $sibling = $this->textField($this->contactGroup($business, 'Other'), 'SIBLING');
        $foreign = $this->textField($this->contactGroup($stranger, 'Theirs'), 'FOREIGN');

        $ownStep = $this->updateStep((int) $own->id);
        $siblingStep = $this->updateStep((int) $sibling->id);
        $foreignStep = $this->updateStep((int) $foreign->id);

        $errors = $this->validate($business, $this->document((int) $watched->id, [$ownStep, $siblingStep, $foreignStep]));

        $this->assertSame([
            $siblingStep['key'] => [self::FIELD_OUTSIDE],
            $foreignStep['key'] => [self::FIELD_OUTSIDE],
        ], $errors);
    }

    public function test_an_update_contact_field_without_a_watched_group_is_refused_without_reading(): void
    {
        [, $business] = $this->entitledTenant();
        $field = $this->textField($this->contactGroup($business, 'Any'), 'NOTE');
        $step = $this->updateStep((int) $field->id);

        [$errors, $queries] = $this->validateCounting($this->version($business, $this->document(null, [$step])));

        $this->assertSame([$step['key'] => [self::FIELD_NEEDS_GROUP]], $errors);
        $this->assertSame([], $queries, 'The refusal needs no row.');
    }

    /** A group with no fields is still a group — that is what the LEFT JOIN is for. */
    public function test_an_in_group_condition_on_a_group_of_this_business_is_accepted_even_with_no_fields(): void
    {
        [, $business] = $this->entitledTenant();
        $watched = $this->contactGroup($business, 'Watched');
        $empty = $this->contactGroup($business, 'Empty');
        ContactGroupFields::query()->where('contact_group_id', $empty->id)->delete();

        $errors = $this->validate($business, $this->document((int) $watched->id, [
            $this->ifElseStep([
                $this->condition('contact.in_group', 'equals', (string) $watched->id),
                $this->condition('contact.in_group', 'not_equals', (int) $empty->id),
            ]),
        ]));

        $this->assertSame([], $errors);
    }

    public function test_an_in_group_condition_on_a_foreign_or_unknown_group_is_refused_by_position(): void
    {
        [, $business] = $this->entitledTenant();
        [, $stranger] = $this->entitledTenant();
        $watched = $this->contactGroup($business, 'Watched');
        $foreign = $this->contactGroup($stranger, 'Theirs');

        $ifElse = $this->ifElseStep([
            $this->condition('contact.in_group', 'equals', (string) $watched->id),
            $this->condition('contact.in_group', 'equals', (string) $foreign->id),
            $this->condition('contact.in_group', 'equals', '99999999'),
        ]);

        $this->assertSame([$ifElse['key'] => [
            'Condition 2 checks a contact group that does not belong to this business.',
            'Condition 3 checks a contact group that does not belong to this business.',
        ]], $this->validate($business, $this->document((int) $watched->id, [$ifElse])));
    }

    public function test_a_custom_field_condition_on_this_business_is_accepted_in_its_own_operator_family(): void
    {
        [, $business] = $this->entitledTenant();
        $watched = $this->contactGroup($business, 'Watched');
        $text = $this->textField($watched, 'NOTE');
        $date = $this->dateField($this->contactGroup($business, 'Other'), 'BIRTH_DATE');

        $errors = $this->validate($business, $this->document((int) $watched->id, [
            $this->ifElseStep([
                $this->condition('contact.custom_field:' . $text->id, 'contains', 'vip'),
                $this->condition('contact.custom_field:' . $date->id, 'is_not_empty'),
            ]),
        ]));

        $this->assertSame([], $errors);
    }

    public function test_a_custom_field_condition_on_a_foreign_or_unknown_field_is_refused_by_position(): void
    {
        [, $business] = $this->entitledTenant();
        [, $stranger] = $this->entitledTenant();
        $watched = $this->contactGroup($business, 'Watched');
        $foreign = $this->textField($this->contactGroup($stranger, 'Theirs'), 'THEIRS');

        $ifElse = $this->ifElseStep([
            $this->condition('contact.custom_field:' . $foreign->id, 'equals', 'x'),
            $this->condition('contact.custom_field:99999999', 'equals', 'x'),
        ]);

        $this->assertSame([$ifElse['key'] => [
            'Condition 1 checks a contact field that does not belong to this business.',
            'Condition 2 checks a contact field that does not belong to this business.',
        ]], $this->validate($business, $this->document((int) $watched->id, [$ifElse])));
    }

    public function test_a_date_field_refuses_a_text_operator_and_a_text_field_refuses_a_date_operator(): void
    {
        [, $business] = $this->entitledTenant();
        $watched = $this->contactGroup($business, 'Watched');
        $text = $this->textField($watched, 'NOTE');
        $date = $this->dateField($watched, 'BIRTH_DATE');

        $ifElse = $this->ifElseStep([
            $this->condition('contact.custom_field:' . $date->id, 'contains', 'June'),
            $this->condition('contact.custom_field:' . $text->id, 'before', '2026-01-01'),
            $this->condition('contact.custom_field:' . $date->id, 'before', '2026-01-01'),
        ]);

        $this->assertSame([$ifElse['key'] => [
            'Condition 1 uses a comparison that does not apply to that field.',
            'Condition 2 uses a comparison that does not apply to that field.',
        ]], $this->validate($business, $this->document((int) $watched->id, [$ifElse])));
    }

    // =================================================================
    // The catalog itself
    // =================================================================

    public function test_the_catalog_holds_only_this_business_keeps_empty_groups_and_partitions_for_the_pickers(): void
    {
        [, $business] = $this->entitledTenant();
        [, $stranger] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Alpha');
        $empty = $this->contactGroup($business, 'Beta');
        ContactGroupFields::query()->where('contact_group_id', $empty->id)->delete();
        $date = $this->dateField($group, 'BIRTH_DATE');
        $note = $this->textField($group, 'NOTE');
        $foreignGroup = $this->contactGroup($stranger, 'Aardvark');
        $foreignField = $this->textField($foreignGroup, 'THEIRS');

        $catalog = app(WorkflowReferenceCatalogLoader::class)->forBusiness($business);

        $this->assertInstanceOf(WorkflowReferenceCatalog::class, $catalog);
        $this->assertSame((int) $business->id, $catalog->businessId);
        $this->assertSame([['id' => (int) $group->id, 'name' => 'Alpha'], ['id' => (int) $empty->id, 'name' => 'Beta']], $catalog->groups());
        $this->assertTrue($catalog->hasGroup((int) $empty->id));
        $this->assertFalse($catalog->hasGroup((int) $foreignGroup->id));
        $this->assertNull($catalog->field((int) $foreignField->id));
        $this->assertTrue($catalog->fieldBelongsToGroup((int) $note->id, (int) $group->id));
        $this->assertFalse($catalog->fieldBelongsToGroup((int) $note->id, (int) $empty->id));

        $ownFieldIds = ContactGroupFields::query()->where('contact_group_id', $group->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame($ownFieldIds, array_column($catalog->fields(), 'id'), 'Every field of this Business, in id order.');
        $this->assertSame([(int) $date->id], array_column($catalog->dateFields(), 'id'));

        $phoneIds = ContactGroupFields::query()->where('contact_group_id', $group->id)->where('is_phone', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotSame([], $phoneIds, 'Precondition: a group is created with its phone field.');
        $this->assertSame(array_values(array_diff($ownFieldIds, $phoneIds)), array_column($catalog->writableFields(), 'id'));
    }

    // -----------------------------------------------------------------

    /**
     * Fifty distinct references, every one owned by $owner.
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function fiftyReferenceDocument(Business $owner): array
    {
        $watched = $this->contactGroup($owner, 'Watched');
        $updates = array_map(fn (int $i) => $this->updateStep((int) $this->textField($watched, 'UPDATE_' . $i)->id), range(1, 19));

        $conditions = [];

        foreach (range(1, 15) as $i) {
            $group = $this->contactGroup($owner, 'Segment ' . $i);
            $conditions[] = $this->condition('contact.in_group', 'equals', (string) $group->id);
            $conditions[] = $this->condition('contact.custom_field:' . $this->textField($group, 'CONDITION_' . $i)->id, 'equals', 'x');
        }

        [$a, $b, $c, $d, $e, $f] = array_chunk($conditions, 5);

        $tree = $this->ifElseStep(
            $a,
            [$this->ifElseStep($b, [$this->ifElseStep($c)], [$this->ifElseStep($d)])],
            [$this->ifElseStep($e, [$this->ifElseStep($f)])],
        );

        return [$this->document((int) $watched->id, [...$updates, $tree]), 1 + count($updates) + count($conditions)];
    }

    /** @return array<string, mixed> */
    private function document(?int $groupId, array $steps): array
    {
        $definition = app(WorkflowDraftService::class)->starterDefinition(WorkflowTriggerType::ContactCreated);

        if ($groupId !== null) {
            $definition['root']['config']['contact_group_id'] = $groupId;
        }

        $definition['root']['next'] = $steps;

        return $definition;
    }

    /** @return array<string, mixed> */
    private function dateDocument(int $groupId, int $dateFieldId): array
    {
        $definition = app(WorkflowDraftService::class)->starterDefinition(WorkflowTriggerType::ContactDateReached);
        $definition['root']['config'] += [
            'contact_group_id' => $groupId,
            'date_field_id' => $dateFieldId,
            'offset' => '0 day',
            'send_at' => '09:00',
        ];
        $definition['root']['next'] = [$this->smsStep()];

        return $definition;
    }

    private function smsStep(): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'send_sms', 'config' => ['body' => 'Hello']];
    }

    private function updateStep(int $fieldId): array
    {
        return ['key' => (string) Str::uuid(), 'type' => 'update_contact_field', 'config' => ['field_id' => $fieldId, 'value' => 'touched']];
    }

    /** The draft version of a new workflow, carrying the document in memory only. */
    private function version(Business $business, array $definition, WorkflowTriggerType $trigger = WorkflowTriggerType::ContactCreated): AutomationWorkflowVersion
    {
        $version = app(WorkflowDraftService::class)
            ->createWorkflowWithDraft($business, 'Reference check ' . uniqid(), $trigger)
            ->draftVersion();

        $version->definition = $definition;

        return $version;
    }

    /** @return array<string, list<string>> */
    private function validate(Business $business, array $definition, WorkflowTriggerType $trigger = WorkflowTriggerType::ContactCreated): array
    {
        return app(WorkflowCompiler::class)->validate($this->version($business, $definition, $trigger));
    }

    /** @return array{0: array<string, list<string>>, 1: list<string>} */
    private function validateCounting(AutomationWorkflowVersion $version): array
    {
        $compiler = app(WorkflowCompiler::class);

        return $this->counting(fn () => $compiler->validate($version));
    }

    /** @return array{0: mixed, 1: list<string>} */
    private function counting(\Closure $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $callback();
        } finally {
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$result, $queries];
    }

    /** @param list<string> $queries */
    private function assertOneCatalogRead(array $queries): void
    {
        $this->assertCount(1, $queries, "Exactly one statement, observed:\n" . implode("\n", $queries));
        $this->assertStringContainsString('left join `contact_group_fields`', $queries[0]);
        $this->assertStringContainsString('`contact_groups`.`business_id` = ?', $queries[0]);
    }
}
