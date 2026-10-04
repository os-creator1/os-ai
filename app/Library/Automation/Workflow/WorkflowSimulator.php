<?php

namespace App\Library\Automation\Workflow;

use App\Enums\Automation\Workflow\NodeSideEffectClass;
use App\Enums\Automation\Workflow\WorkflowEdgeKind;
use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Conditions\ConditionSubjectRegistry;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\Contacts;
use App\Enums\Automation\Workflow\StepRunStatus;

/**
 * Automations V2 §16 — "Test workflow".
 *
 * Answers one question for one real contact: what WOULD this workflow do? It is
 * the only place in v2 that walks a workflow without being a journey, and its
 * entire contract is what it does not do — no enrollment row, no step run, no
 * message, no notification, no contact write, no queued or delayed job, no
 * provider call, and no edit to the draft or the published version it reads.
 *
 * HOW IT AVOIDS BEING A SECOND ENGINE. Three decisions, each of them "reuse the
 * canonical thing":
 *
 *   THE GRAPH is WorkflowCompiler::plan() — the compiler's own traversal of the
 *   document, which compile() then writes verbatim. So the simulated path is the
 *   graph a real enrollment would walk, and it works on a DRAFT, which has no
 *   compiled rows at all. Nothing here parses a document itself.
 *
 *   THE DECISIONS are the registered executors, called through
 *   NodeExecutorRegistry. The Yes/No choice is IfElseNodeExecutor's, using the
 *   real ConditionSubjectRegistry and ConditionEvaluator; the wait instant is
 *   WaitNodeExecutor's. There is no second condition implementation, no second
 *   wait calculation, and no expression evaluation or customer SQL anywhere.
 *
 *   WHICH STEPS MAY ACTUALLY RUN is WorkflowNodeType::sideEffectClass(), the
 *   same classification recovery uses. Class `None` — trigger, wait, if/else,
 *   end — is pure evaluation, so running it for real is both safe and the point.
 *   Everything else, `IdempotentDatabase` and `External` alike, is reported as a
 *   step that WOULD run and is never executed. A contact field update is
 *   idempotent for recovery and still a write, so simulation does not perform
 *   it.
 *
 * WAIT IS PREVIEWED, NEVER ENTERED. A Wait is asked for its instant and the walk
 * then continues straight past it, because the useful answer to "what does this
 * workflow do" is the whole path, not the first day of it. The journey is never
 * parked: parking is an UPDATE on an enrollment, and there is no enrollment.
 *
 * TENANCY FAILS CLOSED. The contact must belong to the same Business as the
 * version, both explicitly; no Business, or another Business, is a refusal
 * rather than a walk. References inside conditions are re-derived by the real
 * executor, so a group or custom field belonging to another Business reads as
 * unset and its condition is false — identical to real execution, never a
 * cross-tenant read.
 *
 * DETERMINISTIC. Two simulations of the same version, contact and clock produce
 * the same path, because every input is a stored value and nothing consults a
 * random source, a queue or the network.
 *
 * HTTP IS NOT HERE. V2-E owns `POST /{workflowUid}/simulate`, its permission and
 * its contact picker. This returns a plain array precisely so that endpoint can
 * encode it without a translation layer.
 */
class WorkflowSimulator
{
    /** Reasons a simulation produces no path at all. */
    public const REFUSED_NO_BUSINESS = 'version_has_no_business';
    public const REFUSED_FOREIGN_CONTACT = 'contact_belongs_to_another_business';
    public const REFUSED_UNWALKABLE = 'workflow_cannot_be_walked';
    public const REFUSED_OUTSIDE_LOCATION = 'contact_outside_workflow_location';

    /** How a walked path finished. */
    public const ENDED_END_STEP = 'end_step';
    public const ENDED_PATH_END = 'path_end';
    public const ENDED_STEP_LIMIT = 'step_limit';
    public const ENDED_STOPPED = 'stopped';

    /** What one simulated step did. */
    public const DID_STARTED = 'started';
    public const DID_WOULD_RUN = 'would_run';
    public const DID_WAITED = 'would_wait';
    public const DID_BRANCHED = 'branched';
    public const DID_ENDED = 'ended';
    public const DID_SKIPPED = 'skipped';
    public const DID_HELD = 'held';

    public function __construct(
        private readonly WorkflowCompiler $compiler,
        private readonly NodeExecutorRegistry $executors,
    ) {
    }

    /**
     * Walk `$version` for `$contact` and report the path.
     *
     * @return array{
     *     workflow_id: int,
     *     version_id: int,
     *     version_state: string,
     *     contact_id: ?int,
     *     refused: ?string,
     *     validation: array<string, list<string>>,
     *     steps: list<array{key: string, type: string, label: string, did: string, detail: ?string, branch: ?string, resume_at: ?string, would_run: bool, side_effect: string}>,
     *     ended: ?string
     * }
     */
    public function simulate(AutomationWorkflowVersion $version, Contacts $contact): array
    {
        $result = [
            'workflow_id' => (int) $version->workflow_id,
            'version_id' => (int) $version->getKey(),
            'version_state' => (string) ($version->state?->value ?? ''),
            'contact_id' => $contact->getKey() === null ? null : (int) $contact->getKey(),
            'refused' => null,
            // Advisory, never a gate: a draft that would not publish is exactly
            // the draft somebody reaches for Test workflow, and it is more
            // useful to show both the reasons and the path than to refuse.
            'validation' => [],
            'steps' => [],
            'ended' => null,
        ];

        $businessId = $version->business_id === null ? 0 : (int) $version->business_id;

        if ($businessId <= 0) {
            $result['refused'] = self::REFUSED_NO_BUSINESS;

            return $result;
        }

        if ($contact->business_id === null || (int) $contact->business_id !== $businessId) {
            // A contact from another Business — or from none — can never be
            // used to probe this Business's workflow, not even read-only.
            $result['refused'] = self::REFUSED_FOREIGN_CONTACT;

            return $result;
        }

        $business = Business::query()->find($businessId);

        if ($business === null) {
            $result['refused'] = self::REFUSED_NO_BUSINESS;

            return $result;
        }

        // A Location-bound version would never enroll a contact of another
        // Location (or of none), so a test with one would show a journey that can
        // not happen. Say so instead.
        if (! $version->scope()->allows($contact->location_id === null ? null : (int) $contact->location_id)) {
            $result['refused'] = self::REFUSED_OUTSIDE_LOCATION;

            return $result;
        }

        $result['validation'] = $this->compiler->validate($version);

        try {
            $plan = $this->compiler->plan($version);
        } catch (\Throwable) {
            // A document the compiler cannot walk — no trigger, an unregistered
            // type — produces no path. The validation errors above already say
            // why, so this is the honest "nothing to show" rather than a guess.
            $result['refused'] = self::REFUSED_UNWALKABLE;

            return $result;
        }

        if ($plan === []) {
            $result['refused'] = self::REFUSED_UNWALKABLE;

            return $result;
        }

        [$result['steps'], $result['ended']] = $this->walk($plan, $version, $business, $contact);

        return $result;
    }

    /**
     * Walk the planned graph from its root.
     *
     * @param array<int, array{key: string, type: WorkflowNodeType, config: array<string, mixed>, depth: int, edges: array<string, string>}> $plan
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function walk(array $plan, AutomationWorkflowVersion $version, Business $business, Contacts $contact): array
    {
        $byKey = [];

        foreach ($plan as $entry) {
            $byKey[$entry['key']] = $entry;
        }

        $enrollment = $this->transientEnrollment($version, $contact);

        $steps = [];
        $current = $plan[0]['key'];
        $seen = [];

        // A compiled graph is a tree, so a bounded walk is a formality — but a
        // tampered document is exactly what a simulation is likely to be pointed
        // at, so the walk is bounded by the same node ceiling a version has and
        // refuses to visit a key twice.
        while (count($steps) < WorkflowLimits::MAX_NODES_PER_VERSION) {
            $entry = $byKey[$current] ?? null;

            if ($entry === null || isset($seen[$current])) {
                return [$steps, self::ENDED_STOPPED];
            }

            $seen[$current] = true;

            [$step, $nextKey, $ended] = $this->simulateOne($entry, $enrollment, $business, $contact);
            $steps[] = $step;

            if ($ended !== null) {
                return [$steps, $ended];
            }

            if ($nextKey === null) {
                return [$steps, self::ENDED_PATH_END];
            }

            $current = $nextKey;
        }

        return [$steps, self::ENDED_STEP_LIMIT];
    }

    /**
     * Simulate one node.
     *
     * @param array{key: string, type: WorkflowNodeType, config: array<string, mixed>, depth: int, edges: array<string, string>} $entry
     * @return array{0: array<string, mixed>, 1: ?string, 2: ?string} the step, the next key, and an end reason when the path stops here
     */
    private function simulateOne(
        array $entry,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): array {
        $type = $entry['type'];
        $sideEffect = $type->sideEffectClass();

        $step = [
            'key' => $entry['key'],
            'type' => $type->value,
            'label' => $type->label(),
            'did' => self::DID_WOULD_RUN,
            'detail' => null,
            'branch' => null,
            'resume_at' => null,
            'would_run' => true,
            'side_effect' => $sideEffect->value,
        ];

        // ANYTHING THAT IS NOT PURE EVALUATION IS DESCRIBED, NOT DONE. No
        // executor is even resolved for it, so there is no path by which a
        // simulation can reach a provider, a mailbox or a contact row.
        if ($sideEffect !== NodeSideEffectClass::None) {
            $step['detail'] = $this->wouldRunDetail($type) . $this->mergePreview($type, $entry['config'], $enrollment, $business, $contact);

            return [$step, $entry['edges'][WorkflowEdgeKind::Next->value] ?? null, null];
        }

        $step['would_run'] = false;

        $executor = $this->executors->for($type);

        if ($executor === null) {
            // The advancer HOLDS a step whose executor has not shipped rather
            // than skipping it, so the honest simulation of one is "the journey
            // would stop here and wait for that slice", not a silent pass.
            $step['did'] = self::DID_HELD;
            $step['detail'] = 'This step cannot run yet.';

            return [$step, null, self::ENDED_STOPPED];
        }

        $outcome = $executor->execute(
            $this->transientNode($entry, $enrollment),
            $enrollment,
            $business,
            $contact,
        );

        return $this->fromOutcome($step, $entry, $type, $outcome);
    }

    /**
     * Turn a real executor's outcome into a simulated step, without performing
     * any of the state changes the advancer would perform for it.
     *
     * @param array<string, mixed> $step
     * @param array{key: string, type: WorkflowNodeType, config: array<string, mixed>, depth: int, edges: array<string, string>} $entry
     * @return array{0: array<string, mixed>, 1: ?string, 2: ?string}
     */
    private function fromOutcome(array $step, array $entry, WorkflowNodeType $type, NodeExecutionOutcome $outcome): array
    {
        $step['detail'] = $outcome->safeResultSummary ?? $outcome->safeErrorSummary;

        if ($outcome->status === StepRunStatus::Waiting && $outcome->resumeAt !== null) {
            // PREVIEW ONLY. The instant is reported; nothing is parked, because
            // parking is an UPDATE on an enrollment and there is none.
            $step['did'] = self::DID_WAITED;
            $step['resume_at'] = $outcome->resumeAt->clone()->utc()->toIso8601String();
            $step['detail'] ??= 'Would wait until this moment, then continue.';

            return [$step, $entry['edges'][WorkflowEdgeKind::Next->value] ?? null, null];
        }

        if ($outcome->branchTaken !== null) {
            $step['did'] = self::DID_BRANCHED;
            $step['branch'] = $outcome->branchTaken->value;

            // A document, payment, appointment or deal condition reads the real event a
            // journey is about; a test contact has none, so it reads as "not set".
            if ($this->readsAnEvent($entry['config'])) {
                $note = 'Conditions about a document, payment, appointment or deal read the real event. A test has none, so they count as not set.';
                $step['detail'] = $step['detail'] === null || $step['detail'] === '' ? $note : $step['detail'] . '. ' . $note;
            }

            // An empty lane has no edge: the journey simply ends there, which is
            // a real and useful thing for Test workflow to show.
            return [$step, $entry['edges'][$outcome->branchTaken->value] ?? null, null];
        }

        if ($outcome->endsEnrollment()) {
            $step['did'] = self::DID_SKIPPED;

            return [$step, null, self::ENDED_STOPPED];
        }

        if ($type === WorkflowNodeType::End) {
            $step['did'] = self::DID_ENDED;

            return [$step, null, self::ENDED_END_STEP];
        }

        if ($type === WorkflowNodeType::Wait) {
            // A wait whose instant has already passed succeeds immediately in
            // the runtime rather than parking. Say that, instead of showing a
            // resume time that is in the past.
            $step['did'] = self::DID_WAITED;
            $step['resume_at'] = null;
            $step['detail'] ??= 'Would not wait: this moment has already passed.';

            return [$step, $entry['edges'][WorkflowEdgeKind::Next->value] ?? null, null];
        }

        // What remains is the trigger: the journey starts here.
        $step['did'] = self::DID_STARTED;

        return [$step, $entry['edges'][WorkflowEdgeKind::Next->value] ?? null, null];
    }

    /**
     * What the customer would actually read, rendered by the SAME merge engine
     * the real send uses, for the test contact. Pure reads — nothing is sent —
     * and an unknown merge field is called out because it would be left blank.
     * Opportunity / Appointment facts are not part of a test run (no trigger
     * happened), so those tokens preview blank.
     *
     * @param array<string, mixed> $config
     */
    private function mergePreview(WorkflowNodeType $type, array $config, AutomationEnrollment $enrollment, Business $business, Contacts $contact): string
    {
        $fields = match ($type) {
            WorkflowNodeType::SendSms => ['Text' => $config['body'] ?? null],
            WorkflowNodeType::SendEmail => ['Subject' => $config['subject'] ?? null, 'Message' => $config['body'] ?? null],
            WorkflowNodeType::InternalNotification => ['Message' => $config['message'] ?? null],
            default => [],
        };

        $lines = [];
        $unknown = [];
        $resolver = app(\App\Library\Merge\MergeFieldResolver::class);

        foreach ($fields as $label => $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $rendered = ContactMergeFields::renderForEnrollment($raw, $contact, $enrollment, $business);
            $lines[] = sprintf('%s: “%s”', $label, \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $rendered) ?? ''), 240));
            array_push($unknown, ...$resolver->unknownTokens($raw, $business));
        }

        if ($lines === []) {
            return '';
        }

        $preview = ' Preview for this contact — ' . implode(' · ', $lines);

        if ($unknown !== []) {
            $preview .= ' (Unknown merge field ' . implode(', ', array_unique($unknown)) . ' will be left blank.)';
        }

        return $preview;
    }

    /**
     * Whether an If / Else step asks about the event behind a journey (a document,
     * payment, appointment or deal) rather than about the contact.
     *
     * @param array<string, mixed> $config
     */
    private function readsAnEvent(array $config): bool
    {
        foreach ((array) ($config['conditions'] ?? []) as $condition) {
            if (is_array($condition) && array_key_exists((string) ($condition['subject'] ?? ''), ConditionSubjectRegistry::FACT_SUBJECTS)) {
                return true;
            }
        }

        return false;
    }

    /** A plain description of a step a simulation refuses to perform. */
    private function wouldRunDetail(WorkflowNodeType $type): string
    {
        return match ($type) {
            WorkflowNodeType::SendSms => 'Would send this text message. Nothing is sent while testing.',
            WorkflowNodeType::UpdateContactField => 'Would update this contact field. Nothing is changed while testing.',
            WorkflowNodeType::InternalNotification => 'Would notify the team. Nobody is notified while testing.',
            WorkflowNodeType::SendEmail => 'Would send this email from your connected mailbox. Nothing is sent while testing.',
            WorkflowNodeType::AddTag => 'Would add this tag to the contact. Nothing is changed while testing.',
            WorkflowNodeType::RemoveTag => 'Would remove this tag from the contact. Nothing is changed while testing.',
            WorkflowNodeType::MoveOpportunity => 'Would move the contact\'s opportunity to this stage. Nothing is changed while testing.',
            WorkflowNodeType::SendBookingLink => 'Would send the booking link. Nothing is sent while testing.',
            WorkflowNodeType::SendForm => 'Would send this form\'s link. Nothing is sent while testing.',
            WorkflowNodeType::SendQuestionnaire => 'Would send this questionnaire\'s link. Nothing is sent while testing.',
            WorkflowNodeType::CreateSendProposal => 'Would create this proposal and email it to the contact. Nothing is created or sent while testing.',
            WorkflowNodeType::RequestPayment => 'Would email the contact a secure payment link. Nothing is sent while testing.',
            default => 'Would run this step. Nothing happens while testing.',
        };
    }

    /**
     * The enrollment a real journey would have — built in memory and never
     * saved.
     *
     * It exists because NodeExecutor::execute() and ConditionSubject::valueFor()
     * take one: the subjects this slice ships read only the contact, but a later
     * subject (V2-F's `contact.replied_since_enrollment`) will read the
     * enrollment, and handing it a correctly-populated transient object is what
     * keeps this simulation honest then too. It has no primary key, so nothing
     * can write against it by accident.
     */
    private function transientEnrollment(AutomationWorkflowVersion $version, Contacts $contact): AutomationEnrollment
    {
        $enrollment = new AutomationEnrollment([
            'workflow_id' => $version->workflow_id,
            'version_id' => $version->getKey(),
            'business_id' => $version->business_id,
            'contact_id' => $contact->getKey(),
            // What a real enrollment would pin: the fact's Location, which for a
            // test is the contact's own (a bound scope only ever admits a contact at
            // one of its Locations, checked above).
            'business_location_id' => $contact->location_id === null ? null : (int) $contact->location_id,
            'step_count' => 0,
        ]);

        $enrollment->exists = false;

        return $enrollment;
    }

    /**
     * The compiled node a real journey would execute — in memory, unsaved.
     *
     * The executors read `config` and nothing else, and an unsaved model has no
     * id to write against, so this is the narrowest possible way to call the
     * real executor on a planned node.
     *
     * @param array{key: string, type: WorkflowNodeType, config: array<string, mixed>, depth: int, edges: array<string, string>} $entry
     */
    private function transientNode(array $entry, AutomationEnrollment $enrollment): AutomationWorkflowNode
    {
        $node = new AutomationWorkflowNode([
            'version_id' => $enrollment->version_id,
            'business_id' => $enrollment->business_id,
            'node_key' => $entry['key'],
            'node_type' => $entry['type']->value,
            'config' => $entry['config'],
            'depth' => $entry['depth'],
        ]);

        $node->exists = false;

        return $node;
    }
}
