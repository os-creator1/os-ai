<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\ContactCreationSource;
use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Contracts\TriggerSource;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationWorkflow;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2 §9 — "a contact is created", with the optional source filter.
 *
 * It runs from a queued job dispatched AFTER COMMIT beside B4's own dispatch, so
 * the Contact row it reads is committed and B4 keeps behaving exactly as before
 * (§15.1 coexistence — this slice adds a second listener, it replaces nothing).
 *
 * WHAT IT DECIDES, AND WHAT IT DOES NOT. It decides which published workflows
 * are listening for this contact's creation. It never creates an enrollment
 * itself: EnrollmentService is the only door (§7.5), and it re-checks tenancy,
 * pins the version, composes the key and refuses duplicates. So this class holds
 * no copy of those rules, and cannot drift from them.
 *
 * THE SOURCE FILTER. A trigger node may carry `source`. Absent or null means
 * "any source". Present means an exact match against the source the creating
 * path declared. A filter naming something outside ContactCreationSource
 * matches NOTHING — an unrecognised filter is a configuration the product
 * cannot honour, and firing "on everything" would be the dangerous reading.
 *
 * A Contact with no Business enrolls nobody, whatever is listening. That is the
 * fail-open default-to-user-1 mistake B4 removed, and it does not come back
 * here.
 */
class ContactCreatedTriggerSource implements TriggerSource
{
    public function __construct(private readonly EnrollmentService $enrollments)
    {
    }

    public function triggerType(): WorkflowTriggerType
    {
        return WorkflowTriggerType::ContactCreated;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Enroll one freshly created Contact into every published workflow that is
     * listening for its source.
     *
     * @return int how many enrollments were created (0 is an ordinary outcome:
     *             nothing listening, a duplicate, or a filter that did not match)
     */
    public function handleContactCreated(Contacts $contact, ContactCreationSource $source): int
    {
        if ($contact->business_id === null) {
            return 0;
        }

        $enrolled = 0;

        foreach ($this->listeningWorkflows($contact, $source) as $workflow) {
            // The occurrence key is the contact id: a contact is created once,
            // so `once_ever` and `once_per_occurrence` agree, and a replayed
            // job composes the same key and loses the same claim.
            $enrollment = $this->enrollments->enroll($workflow, $contact, (string) $contact->getKey());

            if ($enrollment === null) {
                continue;
            }

            $enrolled++;
            AdvanceWorkflowEnrollment::dispatch((int) $enrollment->getKey());
        }

        return $enrolled;
    }

    /**
     * The published workflows of one Business whose pinned trigger is
     * contact_created and whose source filter admits this source.
     *
     * One query for the candidates (workflow row + its compiled trigger node's
     * config), one to load the matching models. Bounded by the Business's own
     * published-workflow limit (§8.4), and capped again here so a pathological
     * account cannot turn one contact into unbounded work.
     *
     * @return iterable<AutomationWorkflow>
     */
    private function listeningWorkflows(Contacts $contact, ContactCreationSource $source): iterable
    {
        $businessId = (int) $contact->business_id;

        $candidates = DB::table('automation_workflows as w')
            ->join('automation_workflow_versions as v', 'v.id', '=', 'w.published_version_id')
            ->join('automation_workflow_nodes as n', function ($join): void {
                $join->on('n.version_id', '=', 'v.id')->where('n.node_type', '=', 'trigger');
            })
            ->where('w.business_id', $businessId)
            ->where('w.status', WorkflowStatus::Published->value)
            ->where('v.trigger_type', WorkflowTriggerType::ContactCreated->value)
            ->orderBy('w.id')
            ->limit(WorkflowLimits::MAX_PUBLISHED_WORKFLOWS_PER_BUSINESS)
            ->get(['w.id as workflow_id', 'n.config as trigger_config']);

        $matching = [];

        foreach ($candidates as $candidate) {
            if ($this->triggerAdmits($candidate->trigger_config, $contact, $source)) {
                $matching[] = (int) $candidate->workflow_id;
            }
        }

        if ($matching === []) {
            return [];
        }

        return AutomationWorkflow::query()
            ->whereIn('id', $matching)
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether this workflow's trigger admits this contact: the source filter,
     * and the optional group filter.
     *
     * Both come from V2-0's own trigger vocabulary
     * (NodeTypeRegistry::CONTACT_SOURCES, and `contact_group_id` where "any
     * group" is valid), which the validator enforces at publish time. This
     * slice reads that configuration; it does not invent a second one.
     *
     * @param string|array<string, mixed>|null $rawConfig the compiled trigger
     *        node's config, as the query returned it
     */
    private function triggerAdmits(string|array|null $rawConfig, Contacts $contact, ContactCreationSource $source): bool
    {
        $config = is_string($rawConfig) ? json_decode($rawConfig, true) : $rawConfig;

        if (! is_array($config)) {
            // A trigger node we cannot read is not a licence to message
            // everyone; it is a workflow that does not fire.
            return false;
        }

        // Absent means "any", which is what the builder's default writes.
        $filter = $config['source'] ?? 'any';

        if (! is_string($filter) || ! $source->matchesFilter($filter)) {
            return false;
        }

        $groupId = $config['contact_group_id'] ?? null;

        if ($groupId === null || $groupId === '') {
            return true;
        }

        // A workflow watching one group must not fire for another group's
        // contact, however the contact was created.
        return (int) $groupId === (int) $contact->group_id;
    }
}
