<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\EnrollmentPolicy;
use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Helpers\Helper;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Contracts\TriggerSource;
use App\Library\Automation\Workflow\NodeTypeRegistry;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationWorkflow;
use App\Models\Contacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2 §9 — "a contact date arrives".
 *
 * This is the workflow trigger only. It is not a calendar, not appointments and
 * not scheduling: it reads a date the customer already stores on their contacts
 * (a birthday, a renewal) and enrolls when that date comes round.
 *
 * THE RULE IS B4's, PORTED, NOT REINVENTED (§9, `AutomationTriggerEvaluator::
 * dueForDateReached()`):
 *
 *   - the timezone is the BUSINESS's own, never a user's and never the server's;
 *   - nothing is due before the configured `send_at` in the Business's local day;
 *   - the occurrence date is the local day shifted by the configured offset, and
 *     the occurrence KEY is that shifted date's four-digit year — so a December
 *     occurrence sent in January cannot collide with next year's key;
 *   - only subscribed contacts, in the configured group, whose configured date
 *     field's month-day equals the occurrence's month-day.
 *
 * WHY THE YEAR IS THE KEY. The policy for this trigger is
 * `once_per_occurrence`, so the claim is "this contact, this workflow, this
 * year". A sweep that runs every day for the rest of the year therefore enrolls
 * nobody twice, and no state outside the enrollment row is needed to remember
 * that.
 *
 * BOUNDED, AND IT MAKES PROGRESS. Workflows are walked in keyset pages by id.
 * Due contacts are read in pages too, and — this is what makes a capped run
 * useful — contacts who already hold this year's enrollment are excluded IN SQL
 * by their enrollment key. A capped run therefore leaves the next run starting
 * at the first contact who still needs enrolling, instead of re-reading the ones
 * it already did.
 */
class DateReachedTriggerSource implements TriggerSource
{
    /**
     * Stands in for a contact id while asking EnrollmentPolicy what a key looks
     * like. Negative because no real id is, so its decimal form cannot appear
     * anywhere else inside a composed key.
     */
    private const CONTACT_ID_PROBE = -1;

    /**
     * The offset vocabulary is V2-0's (NodeTypeRegistry::DATE_OFFSET_ALLOWLIST),
     * which the validator already enforces at publish time — not B4's, whose
     * values carried a sign this one does not. Reading it from there rather than
     * copying it means a publishable workflow and a sweepable one can never
     * disagree.
     *
     * The mechanic stays B4's: the sweep looks AHEAD by the offset
     * (`modify('+' . $offset)`), so "1 week" means the message goes out one week
     * before the stored date.
     */
    public static function offsetAllowlist(): array
    {
        return NodeTypeRegistry::DATE_OFFSET_ALLOWLIST;
    }

    public function __construct(private readonly EnrollmentService $enrollments)
    {
    }

    public function triggerType(): WorkflowTriggerType
    {
        return WorkflowTriggerType::ContactDateReached;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * One bounded sweep pass.
     *
     * @param int $limit maximum enrollments to create in this invocation
     * @return array{considered: int, enrolled: int} considered counts workflows
     *         inspected, so an operator can see the sweep is bounded
     */
    public function sweep(CarbonImmutable $now, int $limit): array
    {
        $enrolled = 0;
        $considered = 0;
        $afterWorkflowId = 0;

        while ($enrolled < $limit) {
            $workflows = $this->publishedDateWorkflows($afterWorkflowId, WorkflowLimits::SWEEP_CHUNK_SIZE);

            if ($workflows === []) {
                break;
            }

            foreach ($workflows as $row) {
                // The cap is checked before anything is counted, so `considered`
                // means "inspected" and not "seen in a page we abandoned".
                if ($enrolled >= $limit) {
                    break 2;
                }

                $considered++;
                $afterWorkflowId = max($afterWorkflowId, (int) $row->workflow_id);

                $enrolled += $this->sweepOneWorkflow($row, $now, $limit - $enrolled);
            }
        }

        return ['considered' => $considered, 'enrolled' => $enrolled];
    }

    /**
     * @param object{workflow_id: int, business_id: int, timezone: ?string, trigger_config: string|array|null, enrollment_policy: ?string} $row
     */
    private function sweepOneWorkflow(object $row, CarbonImmutable $now, int $remaining): int
    {
        $config = $this->readConfig($row->trigger_config);

        if ($config === null) {
            return 0;
        }

        $timezone = $row->timezone ?: (string) config('app.timezone', 'UTC');
        $localNow = $now->setTimezone($timezone);
        $sendAtToday = $localNow->setTimeFromTimeString($config['send_at']);

        // Half-open on the send time: exactly at send_at IS due, a second
        // before is not. The sweep re-runs harmlessly because the claim is
        // durable.
        if ($localNow->lessThan($sendAtToday)) {
            return 0;
        }

        $occurrence = $localNow->modify('+' . $config['offset']);
        $occurrenceYear = (string) $occurrence->format('Y');

        $workflow = AutomationWorkflow::query()->find((int) $row->workflow_id);

        if ($workflow === null) {
            return 0;
        }

        $enrolled = 0;
        $afterContactId = 0;

        // The policy the PUBLISHED version actually carries, not an assumption:
        // a date workflow is `once_per_occurrence` by default, but a deliberate
        // `once_ever` one is valid (§7.5), and its claim key has no occurrence
        // in it. Reading the real policy is what keeps the exclusion below
        // matching the keys EnrollmentService will compose.
        $policy = EnrollmentPolicy::tryFrom((string) ($row->enrollment_policy ?? '')) ?? self::expectedPolicy();

        // Due contacts are read in pages of SWEEP_CHUNK_SIZE — chunkById's own
        // mechanic, written out because the loop also has to stop at the
        // enrollment cap, which chunkById knows nothing about. The keyset on
        // contacts.id is what guarantees this loop terminates even when a page
        // enrolls nobody (every contact in it already active on another
        // occurrence, say): the page after it starts past them.
        while ($enrolled < $remaining) {
            $page = $this->dueContacts(
                $workflow,
                $config,
                $occurrence->format('m-d'),
                $occurrenceYear,
                $policy,
                $afterContactId,
                min($remaining - $enrolled, WorkflowLimits::SWEEP_CHUNK_SIZE),
            );

            if ($page === []) {
                break;
            }

            foreach ($page as $contact) {
                $afterContactId = max($afterContactId, (int) $contact->getKey());

                $enrollment = $this->enrollments->enroll($workflow, $contact, $occurrenceYear);

                if ($enrollment === null) {
                    continue;
                }

                $enrolled++;
                AdvanceWorkflowEnrollment::dispatch((int) $enrollment->getKey());
            }
        }

        return $enrolled;
    }

    /**
     * The published workflows whose pinned trigger is contact_date_reached, one
     * keyset page at a time, with the Business timezone the rule needs.
     *
     * @return array<int, object>
     */
    private function publishedDateWorkflows(int $afterWorkflowId, int $limit): array
    {
        return DB::table('automation_workflows as w')
            ->join('automation_workflow_versions as v', 'v.id', '=', 'w.published_version_id')
            ->join('automation_workflow_nodes as n', function ($join): void {
                $join->on('n.version_id', '=', 'v.id')->where('n.node_type', '=', 'trigger');
            })
            ->join('businesses as b', 'b.id', '=', 'w.business_id')
            ->where('w.status', WorkflowStatus::Published->value)
            ->where('v.trigger_type', WorkflowTriggerType::ContactDateReached->value)
            ->where('w.id', '>', $afterWorkflowId)
            ->orderBy('w.id')
            ->limit($limit)
            ->get([
                'w.id as workflow_id',
                'w.business_id',
                'b.timezone',
                'n.config as trigger_config',
                'v.enrollment_policy',
            ])
            ->all();
    }

    /**
     * One page of contacts due for this occurrence who do not already hold this
     * year's enrollment.
     *
     * The exclusion is the whole reason a capped RUN progresses: it is an
     * equality lookup on `automation_enrollments.enrollment_key`, which is
     * UNIQUE, so already-enrolled contacts are skipped by the index rather than
     * fetched and refused one at a time. The keyset on `contacts.id` is what
     * makes one run's paging progress.
     *
     * @param array{group_id: int, field_id: int, offset: string, send_at: string} $config
     * @return list<Contacts>
     */
    private function dueContacts(
        AutomationWorkflow $workflow,
        array $config,
        string $occurrenceMonthDay,
        string $occurrenceYear,
        EnrollmentPolicy $policy,
        int $afterContactId,
        int $limit,
    ): array {
        [$keyPrefix, $keySuffix] = $this->keyFragmentsAround($workflow, $policy, $occurrenceYear);

        return Contacts::query()
            ->select('contacts.*')
            ->where('contacts.business_id', $workflow->business_id)
            ->where('contacts.group_id', $config['group_id'])
            ->where('contacts.status', Contacts::STATUS_SUBSCRIBE)
            ->where('contacts.id', '>', $afterContactId)
            ->join('contacts_custom_field', 'contacts.id', '=', 'contacts_custom_field.contact_id')
            ->where('contacts_custom_field.field_id', $config['field_id'])
            ->where(
                DB::raw("DATE_FORMAT(STR_TO_DATE(" . Helper::table('contacts_custom_field.value') . ", '" . config('custom.date_format_sql') . "'), '%m-%d')"),
                '=',
                $occurrenceMonthDay,
            )
            ->whereNotExists(function ($query) use ($keyPrefix, $keySuffix): void {
                $query->select(DB::raw(1))
                    ->from('automation_enrollments')
                    ->whereRaw(
                        'automation_enrollments.enrollment_key = CONCAT(?, contacts.id, ?)',
                        [$keyPrefix, $keySuffix],
                    );
            })
            ->orderBy('contacts.id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * The two halves of this occurrence's enrollment key, either side of where
     * a contact id goes.
     *
     * THE SHAPE OF THE KEY IS NOT KNOWN HERE. It is asked for: the canonical
     * EnrollmentPolicy::enrollmentKey() composes one key around a probe that
     * cannot occur anywhere else in it (no real id is negative, and the key
     * holds nothing but digits, colons and the occurrence), and the halves are
     * taken from that. So the SQL exclusion below always matches exactly the
     * keys EnrollmentService will compose — including for a deliberate
     * `once_ever` date workflow, whose key carries no occurrence at all — and a
     * future change to the key format cannot leave this query behind.
     *
     * @return array{0: string, 1: string}
     */
    private function keyFragmentsAround(
        AutomationWorkflow $workflow,
        EnrollmentPolicy $policy,
        string $occurrenceYear,
    ): array {
        $probe = $policy->enrollmentKey((int) $workflow->getKey(), self::CONTACT_ID_PROBE, $occurrenceYear);

        [$prefix, $suffix] = explode((string) self::CONTACT_ID_PROBE, $probe, 2);

        return [$prefix, $suffix];
    }

    /**
     * The trigger node's date configuration, or null when it is incomplete or
     * outside the allowlist — in which case the workflow enrolls nobody rather
     * than guessing a date.
     *
     * @param string|array<string, mixed>|null $rawConfig
     * @return array{group_id: int, field_id: int, offset: string, send_at: string}|null
     */
    private function readConfig(string|array|null $rawConfig): ?array
    {
        $config = is_string($rawConfig) ? json_decode($rawConfig, true) : $rawConfig;

        if (! is_array($config)) {
            return null;
        }

        $groupId = isset($config['contact_group_id']) ? (int) $config['contact_group_id'] : 0;
        $fieldId = isset($config['date_field_id']) ? (int) $config['date_field_id'] : 0;
        $offset = (string) ($config['offset'] ?? '0 day');
        $sendAt = (string) ($config['send_at'] ?? '');

        if ($groupId <= 0 || $fieldId <= 0) {
            return null;
        }

        if (! in_array($offset, self::offsetAllowlist(), true) || preg_match('/^\d{2}:\d{2}$/', $sendAt) !== 1) {
            return null;
        }

        return ['group_id' => $groupId, 'field_id' => $fieldId, 'offset' => $offset, 'send_at' => $sendAt];
    }

    /**
     * The policy this trigger must carry for the year-based claim to mean
     * anything. Read from the enum so there is one source of truth.
     */
    public static function expectedPolicy(): EnrollmentPolicy
    {
        return WorkflowTriggerType::ContactDateReached->defaultEnrollmentPolicy();
    }
}
