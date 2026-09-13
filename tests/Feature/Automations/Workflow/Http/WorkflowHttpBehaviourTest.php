<?php

namespace Tests\Feature\Automations\Workflow\Http;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Jobs\Automation\Workflow\EnrollWorkflowContact;
use App\Jobs\Automation\Workflow\RedispatchHeldEnrollments;
use App\Library\Automation\Workflow\Contracts\WorkflowLifecycle;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-E — what each endpoint does once a caller is allowed in.
 *
 * The services these call are already proven in their own lanes. What is proven
 * here is the WIRING: the right service is reached, its answer carries the right
 * status, and the HTTP layer adds no behaviour of its own — in particular that a
 * test run writes nothing, that a stale save is refused rather than applied, and
 * that a manual enrollment list is honoured whole or not at all.
 */
class WorkflowHttpBehaviourTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use CallsWorkflowRoutes;

    /** @return array<string, mixed> */
    private function signedInTenant(): array
    {
        $tenant = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($tenant['customer']);

        return $tenant;
    }

    /** @param array<string, mixed> $t */
    private function url(array $t, string $name, AutomationWorkflow|string|null $workflow = null, $enrollment = null): string
    {
        return $this->routeUrl($name, $t['workspace'], $t['business'], $workflow, $enrollment);
    }

    // ---------------------------------------------------------------
    // List, create, show, settings
    // ---------------------------------------------------------------

    public function test_the_list_holds_only_this_businesss_workflows(): void
    {
        $t = $this->signedInTenant();
        $other = $this->tenantWithWorkflow();

        $response = $this->callJson('GET', $this->url($t, 'index'))->assertOk();

        $uids = array_column($response->json('workflows'), 'uid');
        $this->assertContains($t['workflow']->uid, $uids);
        $this->assertNotContains($other['workflow']->uid, $uids, 'Another Business\'s workflow must never be listed.');
    }

    public function test_creating_a_workflow_starts_it_with_a_draft(): void
    {
        $t = $this->signedInTenant();

        $response = $this->callJson('POST', $this->url($t, 'store'), [
            'name' => 'Birthday wishes',
            'trigger_type' => 'contact_date_reached',
        ])->assertCreated();

        $response->assertJsonPath('workflow.name', 'Birthday wishes')
            ->assertJsonPath('workflow.status', WorkflowStatus::Draft->value)
            ->assertJsonPath('workflow.has_draft', true);

        $workflow = AutomationWorkflow::query()->where('uid', $response->json('workflow.uid'))->firstOrFail();
        $this->assertSame((int) $t['business']->id, (int) $workflow->business_id, 'Created inside the resolved Business.');
    }

    /**
     * The trigger rule follows WorkflowTriggerType::isIngestableInThisSlice(), not
     * a list written here. When this test was first written `message_received` was
     * refused because nothing reported it; V2-F (#281) shipped its producer, so it
     * is now accepted — and a value that is no trigger at all is still refused.
     */
    public function test_the_trigger_rule_follows_what_the_product_can_report(): void
    {
        $t = $this->signedInTenant();

        $this->callJson('POST', $this->url($t, 'store'), ['name' => 'Nonsense', 'trigger_type' => 'not_a_trigger'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('trigger_type');

        $this->assertTrue(\App\Enums\Automation\Workflow\WorkflowTriggerType::MessageReceived->isIngestableInThisSlice());
        $this->callJson('POST', $this->url($t, 'store'), ['name' => 'Replies', 'trigger_type' => 'message_received'])
            ->assertCreated();

        $this->callJson('POST', $this->url($t, 'store'), ['trigger_type' => 'contact_created'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_settings_show_the_live_rules_beside_the_drafts(): void
    {
        $t = $this->signedInTenant();

        $this->callJson('GET', $this->url($t, 'settings', $t['workflow']))
            ->assertOk()
            ->assertJsonPath('workflow.uid', $t['workflow']->uid)
            ->assertJsonPath('published.state', 'published')
            ->assertJsonPath('draft', null);
    }

    // ---------------------------------------------------------------
    // Draft, autosave, publish, discard
    // ---------------------------------------------------------------

    public function test_opening_the_draft_returns_the_document_revision_and_errors(): void
    {
        $t = $this->signedInTenant();

        $response = $this->callJson('GET', $this->url($t, 'draft.show', $t['workflow']))->assertOk();

        $this->assertIsArray($response->json('definition'));
        $this->assertIsInt($response->json('revision'));
        $this->assertIsArray($response->json('errors'));

        // Cloned from the published document, so a live workflow's editor always
        // has a real revision to save against.
        $this->assertNotNull($t['workflow']->fresh()->draftVersion());
    }

    public function test_autosave_saves_and_reports_errors_even_for_an_invalid_document(): void
    {
        $t = $this->signedInTenant();
        $draft = $this->callJson('GET', $this->url($t, 'draft.show', $t['workflow']))->json();

        $definition = $draft['definition'];
        $invalidKey = (string) Str::uuid();
        // An SMS step with no body: saved, reported, not publishable.
        $definition['root']['next'] = [['key' => $invalidKey, 'type' => 'send_sms', 'config' => ['body' => '']]];

        $response = $this->callJson('PUT', $this->url($t, 'draft.autosave', $t['workflow']), [
            'definition' => $definition,
            'definition_revision' => $draft['revision'],
        ])->assertOk();

        $this->assertSame($draft['revision'] + 1, $response->json('revision'), 'The save moved the revision.');
        $this->assertArrayHasKey($invalidKey, $response->json('errors'), 'Its errors are reported, keyed by node.');

        $saved = $t['workflow']->fresh()->draftVersion()->definition['root']['next'][0];

        $this->assertSame($invalidKey, $saved['key'], '§14.4: an invalid document is still saved, so work is never lost.');

        // Empty, not necessarily "". Laravel's global ConvertEmptyStringsToNull
        // middleware rewrites "" to null throughout the request — including
        // inside this nested document — before any of this code sees it. The
        // validator treats both as "no body", so the document means the same
        // thing either way.
        $this->assertEmpty($saved['config']['body']);
    }

    /** §14.4 — a second tab's stale save is a 409, never an overwrite. */
    public function test_a_stale_autosave_is_a_conflict_and_changes_nothing(): void
    {
        $t = $this->signedInTenant();
        $draft = $this->callJson('GET', $this->url($t, 'draft.show', $t['workflow']))->json();

        $first = $draft['definition'];
        $first['root']['next'] = [$this->endStep()];

        $this->callJson('PUT', $this->url($t, 'draft.autosave', $t['workflow']), [
            'definition' => $first,
            'definition_revision' => $draft['revision'],
        ])->assertOk();

        $stale = $draft['definition'];
        $stale['root']['next'] = [];

        $this->callJson('PUT', $this->url($t, 'draft.autosave', $t['workflow']), [
            'definition' => $stale,
            'definition_revision' => $draft['revision'], // the revision the OTHER tab already used
        ])->assertStatus(409);

        $this->assertCount(
            1,
            $t['workflow']->fresh()->draftVersion()->definition['root']['next'],
            'The first tab\'s save must survive the second tab\'s stale one.',
        );
    }

    public function test_an_oversized_document_is_refused(): void
    {
        $t = $this->signedInTenant();
        $draft = $this->callJson('GET', $this->url($t, 'draft.show', $t['workflow']))->json();

        $definition = $draft['definition'];
        $definition['padding'] = str_repeat('x', WorkflowLimits::MAX_DEFINITION_BYTES + 10);

        $this->callJson('PUT', $this->url($t, 'draft.autosave', $t['workflow']), [
            'definition' => $definition,
            'definition_revision' => $draft['revision'],
        ])->assertStatus(422)->assertJsonValidationErrors('definition');
    }

    public function test_an_archived_workflow_cannot_be_edited(): void
    {
        $t = $this->signedInTenant();
        app(WorkflowLifecycle::class)->archive($t['workflow']);

        $this->callJson('GET', $this->url($t, 'draft.show', $t['workflow']))->assertStatus(409);
        $this->assertNull($t['workflow']->fresh()->draftVersion(), 'No dead draft may be created for it.');
    }

    public function test_publishing_a_valid_draft_succeeds(): void
    {
        $t = $this->signedInTenant();
        $draft = $this->callJson('GET', $this->url($t, 'draft.show', $t['workflow']))->json();

        $response = $this->callJson('POST', $this->url($t, 'publish', $t['workflow']))->assertOk();

        $response->assertJsonPath('workflow_uid', $t['workflow']->uid)
            ->assertJsonPath('status', WorkflowStatus::Published->value);
        $this->assertNotSame($draft['uid'], null);
        $this->assertNull($t['workflow']->fresh()->draftVersion(), 'The draft became the live version.');
    }

    /** 422 with errors keyed by node key, per §20.2. */
    public function test_publishing_an_invalid_draft_is_refused_with_errors_by_node(): void
    {
        $t = $this->signedInTenant();
        $draft = $this->callJson('GET', $this->url($t, 'draft.show', $t['workflow']))->json();

        $badKey = (string) Str::uuid();
        $definition = $draft['definition'];
        $definition['root']['next'] = [['key' => $badKey, 'type' => 'send_sms', 'config' => ['body' => '']]];

        $this->callJson('PUT', $this->url($t, 'draft.autosave', $t['workflow']), [
            'definition' => $definition,
            'definition_revision' => $draft['revision'],
        ])->assertOk();

        $response = $this->callJson('POST', $this->url($t, 'publish', $t['workflow']))->assertStatus(422);

        $this->assertArrayHasKey($badKey, $response->json('errors'), 'Errors must be keyed by the node that has them.');
    }

    public function test_publishing_with_no_draft_is_refused(): void
    {
        $t = $this->signedInTenant();

        $this->callJson('POST', $this->url($t, 'publish', $t['workflow']))->assertStatus(422);
    }

    public function test_discarding_the_draft_removes_it(): void
    {
        $t = $this->signedInTenant();
        $this->callJson('GET', $this->url($t, 'draft.show', $t['workflow']))->assertOk();

        $this->callJson('POST', $this->url($t, 'discard-draft', $t['workflow']))
            ->assertOk()
            ->assertJsonPath('has_draft', false);

        $this->assertSame(WorkflowStatus::Published, $t['workflow']->fresh()->status, 'The live version is untouched.');
    }

    // ---------------------------------------------------------------
    // Simulate
    // ---------------------------------------------------------------

    /** A test run is a read: no enrollment, no step run, no job, no write. */
    public function test_simulating_writes_nothing_and_queues_nothing(): void
    {
        $t = $this->signedInTenant();
        [$workflow] = $this->publishWorkflow($t['business'], [
            ['key' => (string) Str::uuid(), 'type' => 'send_sms', 'config' => ['body' => 'Hello']],
            $this->endStep(),
        ], name: 'Simulated');
        $contact = $this->contactFor($t['business'], 'Sim');

        $before = [
            'enrollments' => DB::table('automation_enrollments')->count(),
            'step_runs' => DB::table('automation_step_runs')->count(),
            'versions' => DB::table('automation_workflow_versions')->count(),
        ];

        Bus::fake();

        $response = $this->callJson('POST', $this->url($t, 'simulate', $workflow), ['contact_uid' => $contact->uid])
            ->assertOk();

        $this->assertNull($response->json('refused'));
        $this->assertNotEmpty($response->json('steps'), 'The path is reported.');

        $this->assertSame($before, [
            'enrollments' => DB::table('automation_enrollments')->count(),
            'step_runs' => DB::table('automation_step_runs')->count(),
            'versions' => DB::table('automation_workflow_versions')->count(),
        ], 'Simulation must not enroll, run a step, or create a version.');

        Bus::assertNothingDispatched();
    }

    public function test_simulating_another_businesss_contact_is_not_found(): void
    {
        $t = $this->signedInTenant();
        $other = $this->tenantWithWorkflow();

        $this->callJson('POST', $this->url($t, 'simulate', $t['workflow']), ['contact_uid' => $other['contact']->uid])
            ->assertNotFound();

        $this->callJson('POST', $this->url($t, 'simulate', $t['workflow']), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_uid');
    }

    /** It tests what the customer is editing: the draft when there is one. */
    public function test_simulating_uses_the_draft_when_one_exists(): void
    {
        $t = $this->signedInTenant();
        $draft = $this->callJson('GET', $this->url($t, 'draft.show', $t['workflow']))->json();

        $response = $this->callJson('POST', $this->url($t, 'simulate', $t['workflow']), ['contact_uid' => $t['contact']->uid])
            ->assertOk();

        $this->assertSame('draft', $response->json('version_state'));
        $this->assertNotNull($draft);
    }

    // ---------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------

    public function test_pause_and_resume_report_the_real_transition(): void
    {
        $t = $this->signedInTenant();

        $this->callJson('POST', $this->url($t, 'pause', $t['workflow']))
            ->assertOk()
            ->assertJsonPath('workflow.status', WorkflowStatus::Paused->value)
            ->assertJsonPath('changed', true);

        // Pausing again is a harmless no-op, reported honestly as unchanged.
        $this->callJson('POST', $this->url($t, 'pause', $t['workflow']))
            ->assertOk()
            ->assertJsonPath('changed', false);

        Bus::fake([RedispatchHeldEnrollments::class, AdvanceWorkflowEnrollment::class]);

        $this->callJson('POST', $this->url($t, 'resume', $t['workflow']))
            ->assertOk()
            ->assertJsonPath('workflow.status', WorkflowStatus::Published->value)
            ->assertJsonPath('changed', true);

        // Resume's re-dispatch comes from the lifecycle service, not from here.
        Bus::assertDispatched(RedispatchHeldEnrollments::class);
    }

    public function test_pausing_a_draft_changes_nothing(): void
    {
        $t = $this->signedInTenant();
        $created = $this->callJson('POST', $this->url($t, 'store'), ['name' => 'Unpublished', 'trigger_type' => 'contact_created'])
            ->json('workflow.uid');

        $this->callJson('POST', $this->url($t, 'pause', $created))
            ->assertOk()
            ->assertJsonPath('workflow.status', WorkflowStatus::Draft->value)
            ->assertJsonPath('changed', false);
    }

    public function test_archive_cancels_journeys_in_flight(): void
    {
        $t = $this->signedInTenant();

        $this->callJson('POST', $this->url($t, 'archive', $t['workflow']))
            ->assertOk()
            ->assertJsonPath('workflow.status', WorkflowStatus::Archived->value);

        $this->assertSame(EnrollmentStatus::Cancelled, $t['enrollment']->fresh()->status);
    }

    public function test_stop_all_cancels_and_reports_the_count(): void
    {
        $t = $this->signedInTenant();

        $this->callJson('POST', $this->url($t, 'stop-all', $t['workflow']))
            ->assertOk()
            ->assertJsonPath('cancelled', 1);

        $enrollment = $t['enrollment']->fresh();
        $this->assertSame(EnrollmentStatus::Cancelled, $enrollment->status);
        $this->assertSame('stopped_by_user', $enrollment->exit_reason);
    }

    // ---------------------------------------------------------------
    // Enrollments and logs
    // ---------------------------------------------------------------

    public function test_the_enrollment_list_is_scoped_to_this_workflow(): void
    {
        $t = $this->signedInTenant();
        [$sibling] = $this->publishWorkflow($t['business'], [$this->endStep()], name: 'Sibling');
        $siblingContact = $this->contactFor($t['business'], 'Sibling');
        app(\App\Library\Automation\Workflow\Contracts\EnrollmentService::class)
            ->enroll($sibling, $siblingContact, (string) $siblingContact->id);

        $response = $this->callJson('GET', $this->url($t, 'enrollments.index', $t['workflow']))->assertOk();

        $this->assertSame([$t['enrollment']->uid], array_column($response->json('enrollments'), 'uid'));
        $this->assertSame($t['contact']->uid, $response->json('enrollments.0.contact_uid'));
    }

    public function test_the_log_shows_each_step_of_a_journey(): void
    {
        $t = $this->signedInTenant();
        app(\App\Library\Automation\Workflow\Runtime\WorkflowAdvancer::class)->advance($t['enrollment']);

        $response = $this->callJson('GET', $this->url($t, 'enrollments.logs', $t['workflow'], $t['enrollment']))
            ->assertOk();

        $types = array_column($response->json('steps'), 'node_type');
        $this->assertSame(['trigger', 'end'], $types);
        $this->assertSame('completed', $response->json('status'));
    }

    // ---------------------------------------------------------------
    // Manual enrollment
    // ---------------------------------------------------------------

    /** @return list<Contacts> */
    private function contactsFor(array $t, int $count): array
    {
        $contacts = [];

        for ($i = 0; $i < $count; $i++) {
            $contacts[] = $this->contactFor($t['business'], 'Manual' . $i);
        }

        return $contacts;
    }

    public function test_manual_enrollment_queues_one_canonical_job_per_contact(): void
    {
        $t = $this->signedInTenant();
        $contacts = $this->contactsFor($t, 3);

        Bus::fake([EnrollWorkflowContact::class]);

        $response = $this->callJson('POST', $this->url($t, 'enrollments.manual', $t['workflow']), [
            'contact_uids' => array_map(fn (Contacts $c) => $c->uid, $contacts),
            'confirmed' => true,
        ])->assertStatus(202);

        $this->assertSame(3, $response->json('queued'));
        $this->assertNotEmpty($response->json('request_uid'));
        Bus::assertDispatchedTimes(EnrollWorkflowContact::class, 3);
    }

    /** End to end: the queued job really enrolls through the canonical path. */
    public function test_a_manual_enrollment_really_enrolls_the_contact(): void
    {
        $t = $this->signedInTenant();
        [$contact] = $this->contactsFor($t, 1);

        // Real (sync) queue: the job runs EnrollWorkflowContact →
        // ManualEnrollmentTriggerSource → EnrollmentService.
        $this->callJson('POST', $this->url($t, 'enrollments.manual', $t['workflow']), [
            'contact_uids' => [$contact->uid],
            'confirmed' => true,
        ])->assertStatus(202);

        $enrollment = AutomationEnrollment::query()
            ->where('workflow_id', $t['workflow']->id)
            ->where('contact_id', $contact->id)
            ->first();

        $this->assertNotNull($enrollment, 'The contact must be enrolled through the canonical trigger source.');
        $this->assertSame((int) $t['business']->id, (int) $enrollment->business_id);
    }

    public function test_more_than_the_limit_is_refused_whole(): void
    {
        $t = $this->signedInTenant();

        Bus::fake([EnrollWorkflowContact::class]);

        $this->callJson('POST', $this->url($t, 'enrollments.manual', $t['workflow']), [
            'contact_uids' => array_map(static fn (int $i) => 'uid-' . $i, range(1, WorkflowLimits::MAX_MANUAL_ENROLLMENTS_PER_REQUEST + 1)),
            'confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('contact_uids');

        Bus::assertNotDispatched(EnrollWorkflowContact::class);
    }

    /** Exactly the limit is allowed — the bound is inclusive. */
    public function test_exactly_the_limit_is_accepted(): void
    {
        $t = $this->signedInTenant();
        $limit = WorkflowLimits::MAX_MANUAL_ENROLLMENTS_PER_REQUEST;

        $groupId = (int) $t['contact']->group_id;
        $rows = [];
        $uids = [];

        for ($i = 0; $i < $limit; $i++) {
            $uid = (string) Str::uuid();
            $uids[] = $uid;
            $rows[] = [
                'uid' => $uid,
                'customer_id' => (int) $t['business']->customer_id,
                'business_id' => (int) $t['business']->id,
                'group_id' => $groupId,
                'phone' => 12025500000 + $i,
                'status' => Contacts::STATUS_SUBSCRIBE,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('contacts')->insert($chunk);
        }

        Bus::fake([EnrollWorkflowContact::class]);

        $this->callJson('POST', $this->url($t, 'enrollments.manual', $t['workflow']), [
            'contact_uids' => $uids,
            'confirmed' => true,
        ])->assertStatus(202)->assertJsonPath('queued', $limit);

        Bus::assertDispatchedTimes(EnrollWorkflowContact::class, $limit);
    }

    public function test_an_unconfirmed_manual_enrollment_is_refused(): void
    {
        $t = $this->signedInTenant();
        [$contact] = $this->contactsFor($t, 1);

        Bus::fake([EnrollWorkflowContact::class]);

        $this->callJson('POST', $this->url($t, 'enrollments.manual', $t['workflow']), [
            'contact_uids' => [$contact->uid],
        ])->assertStatus(422)->assertJsonValidationErrors('confirmed');

        Bus::assertNotDispatched(EnrollWorkflowContact::class);
    }

    // ---------------------------------------------------------------
    // T-WF-21 — unknown and foreign contacts are 404-equivalent
    // ---------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function enrollByHand(array $t, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->callJson('POST', $this->url($t, 'enrollments.manual', $t['workflow']), $body);
    }

    /** A contact that exists nowhere: not found, nothing enqueued. */
    public function test_an_unknown_contact_is_not_found_and_enrolls_nobody(): void
    {
        $t = $this->signedInTenant();

        Bus::fake([EnrollWorkflowContact::class]);

        $this->enrollByHand($t, ['contact_uids' => [(string) Str::uuid()], 'confirmed' => true])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);

        Bus::assertNotDispatched(EnrollWorkflowContact::class);
    }

    /** Another Business's contact: the same not-found, nothing enqueued. */
    public function test_a_foreign_business_contact_is_not_found_and_enrolls_nobody(): void
    {
        $t = $this->signedInTenant();
        $other = $this->tenantWithWorkflow();

        Bus::fake([EnrollWorkflowContact::class]);

        $this->enrollByHand($t, ['contact_uids' => [$other['contact']->uid], 'confirmed' => true])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);

        Bus::assertNotDispatched(EnrollWorkflowContact::class);
        $this->assertSame(
            0,
            AutomationEnrollment::query()->where('contact_id', $other['contact']->id)->where('workflow_id', $t['workflow']->id)->count(),
        );
    }

    /**
     * The existence-leak proof: an unknown uid and a real contact of another
     * Business produce BYTE-IDENTICAL responses, so the answer cannot be used to
     * learn that some other Business holds a contact with that uid.
     */
    public function test_unknown_and_foreign_contacts_are_indistinguishable(): void
    {
        $t = $this->signedInTenant();
        $other = $this->tenantWithWorkflow();

        Bus::fake([EnrollWorkflowContact::class]);

        $unknown = $this->enrollByHand($t, ['contact_uids' => [(string) Str::uuid()], 'confirmed' => true]);
        $foreign = $this->enrollByHand($t, ['contact_uids' => [$other['contact']->uid], 'confirmed' => true]);

        $this->assertSame($unknown->status(), $foreign->status());
        $this->assertSame($unknown->getContent(), $foreign->getContent(), 'The two must not be told apart by body.');
        $this->assertStringNotContainsString($other['contact']->uid, (string) $foreign->getContent(), 'The foreign uid must not be echoed.');
    }

    /** All or nothing: one foreign contact in a valid batch enrolls NOBODY. */
    public function test_a_batch_with_one_foreign_contact_enrolls_none_of_the_valid_ones(): void
    {
        $t = $this->signedInTenant();
        $mine = $this->contactsFor($t, 3);
        $other = $this->tenantWithWorkflow();

        Bus::fake([EnrollWorkflowContact::class]);

        $this->enrollByHand($t, [
            'contact_uids' => [$mine[0]->uid, $other['contact']->uid, $mine[1]->uid, $mine[2]->uid],
            'confirmed' => true,
        ])->assertNotFound();

        Bus::assertNotDispatched(EnrollWorkflowContact::class);

        foreach ($mine as $contact) {
            $this->assertSame(
                0,
                AutomationEnrollment::query()->where('contact_id', $contact->id)->where('workflow_id', $t['workflow']->id)->count(),
                'A valid contact in a batch that failed must not be enrolled.',
            );
        }
    }

    /** A malformed body is a statement about the request: 422, not 404. */
    public function test_a_malformed_manual_enrollment_body_is_a_validation_error(): void
    {
        $t = $this->signedInTenant();

        Bus::fake([EnrollWorkflowContact::class]);

        $this->enrollByHand($t, ['contact_uids' => 'not-a-list', 'confirmed' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_uids');

        $this->enrollByHand($t, ['contact_uids' => [], 'confirmed' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_uids');

        $this->enrollByHand($t, ['confirmed' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_uids');

        Bus::assertNotDispatched(EnrollWorkflowContact::class);
    }

    public function test_a_workflow_that_is_not_live_cannot_enroll(): void
    {
        $t = $this->signedInTenant();
        [$contact] = $this->contactsFor($t, 1);
        app(WorkflowLifecycle::class)->pause($t['workflow']);

        Bus::fake([EnrollWorkflowContact::class]);

        $this->callJson('POST', $this->url($t, 'enrollments.manual', $t['workflow']), [
            'contact_uids' => [$contact->uid],
            'confirmed' => true,
        ])->assertStatus(409);

        Bus::assertNotDispatched(EnrollWorkflowContact::class);
    }

    // ---------------------------------------------------------------
    // Customer vocabulary
    // ---------------------------------------------------------------

    /** Customer-facing messages say Account and Business, never Workspace. */
    public function test_customer_facing_messages_never_say_workspace(): void
    {
        $t = $this->signedInTenant();
        [, , $otherWorkspace] = $this->entitledTenant();
        app(WorkflowLifecycle::class)->pause($t['workflow']);

        $messages = [
            $this->callJson('GET', $this->routeUrl('index', $otherWorkspace, $t['business']))->json('message'),
            $this->callJson('POST', $this->url($t, 'enrollments.manual', $t['workflow']), [
                'contact_uids' => [$t['contact']->uid], 'confirmed' => true,
            ])->json('message'),
            $this->callJson('POST', $this->url($t, 'store'), ['trigger_type' => 'contact_created'])->json('message'),
        ];

        $this->authenticateAsCustomer($t['customer'], []);
        $messages[] = $this->callJson('GET', $this->url($t, 'index'))->json('message');

        foreach ($messages as $message) {
            $this->assertIsString($message);
            $this->assertStringNotContainsStringIgnoringCase('workspace', $message);
        }
    }
}
