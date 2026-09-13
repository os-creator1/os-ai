<?php

namespace Tests\Feature\Automations\Workflow\Logic\Support;

use App\Models\AutomationEnrollment;
use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step builders for the Wait and If/Else proofs.
 *
 * Workflow publishing itself is NOT redefined here: these tests reuse the
 * runtime lane's BuildsWorkflows, so every graph under test is one the real
 * compiler and publisher actually emit.
 */
trait BuildsLogicWorkflows
{
    /** A Wait step in duration mode. */
    protected function waitStep(int $amount, string $unit): array
    {
        return [
            'key' => (string) Str::uuid(),
            'type' => 'wait',
            'config' => ['mode' => 'duration', 'amount' => $amount, 'unit' => $unit],
        ];
    }

    /** A Wait step in until-datetime mode. `$at` is wall-clock, Business-local. */
    protected function waitUntilStep(string $at): array
    {
        return [
            'key' => (string) Str::uuid(),
            'type' => 'wait',
            'config' => ['mode' => 'until_datetime', 'at' => $at],
        ];
    }

    /**
     * An If/Else step.
     *
     * @param list<array<string, mixed>> $conditions
     * @param list<array<string, mixed>> $yes
     * @param list<array<string, mixed>> $no
     */
    protected function ifElseStep(array $conditions, array $yes = [], array $no = [], string $match = 'all'): array
    {
        return [
            'key' => (string) Str::uuid(),
            'type' => 'if_else',
            'config' => ['match' => $match, 'conditions' => $conditions],
            'yes' => $yes,
            'no' => $no,
        ];
    }

    protected function condition(string $subject, string $operator, mixed $operand = null): array
    {
        $condition = ['subject' => $subject, 'operator' => $operator];

        if ($operand !== null) {
            $condition['operand'] = $operand;
        }

        return $condition;
    }

    /**
     * Create the four identity fields a contact's name card is made of, plus any
     * extra tags asked for.
     *
     * @param  list<string> $extraTags
     * @return array<string, \App\Models\ContactGroupFields> tag => field
     */
    protected function identityFields(\App\Models\ContactGroups $group, array $extraTags = []): array
    {
        $fields = [];

        $identityTags = array_values(\App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry::IDENTITY_SUBJECTS);

        foreach ([...$identityTags, ...$extraTags] as $tag) {
            // A new contact group already ships with PHONE, FIRST_NAME and
            // LAST_NAME. Creating a second field with the same tag would be a
            // fixture that no real group has, and the value would be written to
            // the duplicate while every read found the original — so reuse what
            // is there and only create what is missing.
            $existing = \App\Models\ContactGroupFields::query()
                ->where('contact_group_id', $group->id)
                ->where('tag', $tag)
                ->orderBy('id')
                ->first();

            $fields[$tag] = $existing ?? $this->textField($group, $tag);
        }

        return $fields;
    }

    /**
     * Store custom-field values for a contact.
     *
     * @param array<string, \App\Models\ContactGroupFields> $fields tag => field
     * @param array<string, string>                        $values tag => value
     */
    protected function setContactValues(\App\Models\Contacts $contact, array $fields, array $values): void
    {
        foreach ($values as $tag => $value) {
            if (! isset($fields[$tag])) {
                continue;
            }

            DB::table('contacts_custom_field')->updateOrInsert(
                ['contact_id' => $contact->id, 'field_id' => $fields[$tag]->id],
                ['value' => $value],
            );
        }

        $contact->unsetRelation('contactsFields');
    }

    /** The compiled node id of a recorded step carrying this body. */
    protected function nodeIdByBody(int $versionId, string $body): int
    {
        foreach (DB::table('automation_workflow_nodes')->where('version_id', $versionId)->get() as $row) {
            $config = json_decode((string) $row->config, true);

            if (($config['body'] ?? null) === $body) {
                return (int) $row->id;
            }
        }

        return 0;
    }

    /** The branch an if/else node recorded taking. */
    protected function branchTakenFor(AutomationEnrollment $enrollment): ?string
    {
        return DB::table('automation_step_runs')
            ->where('enrollment_id', $enrollment->id)
            ->where('node_type', 'if_else')
            ->value('branch_taken');
    }

    /** Give a Business an explicit timezone, which Wait must honour. */
    protected function setBusinessTimezone(Business $business, string $timezone): void
    {
        DB::table('businesses')->where('id', $business->id)->update(['timezone' => $timezone]);
    }

    /** Drag a waiting enrollment's wake time into the past. */
    protected function makeWaitDue(AutomationEnrollment $enrollment, ?Carbon $at = null): void
    {
        DB::table('automation_enrollments')->where('id', $enrollment->id)->update([
            'resume_at' => ($at ?? Carbon::now()->subMinute())->utc(),
        ]);
    }

    /** The enrollment's current wait instant, as stored. */
    protected function storedResumeAt(AutomationEnrollment $enrollment): ?Carbon
    {
        $raw = DB::table('automation_enrollments')->where('id', $enrollment->id)->value('resume_at');

        return $raw === null ? null : Carbon::parse($raw, 'UTC');
    }
}
