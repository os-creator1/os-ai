<?php

namespace Tests\Feature\Automations\Workflow\Triggers;

use App\Enums\Automation\Workflow\EnrollmentPolicy;
use App\Enums\Automation\Workflow\EnrollmentPolicySource;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Triggers\DateReachedTriggerSource;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-C — "a contact date arrives", and its sweep.
 *
 * The rule is B4's, ported: the Business's timezone, the send-at gate, the
 * offset-adjusted occurrence, and the occurrence YEAR as the claim. These tests
 * pin the boundary exactly, prove a repeated sweep enrolls nobody twice, and
 * prove a capped run makes real forward progress rather than re-reading the
 * contacts it already handled.
 */
class DateReachedTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;

    private int $sweptQueries = 0;

    private function trigger(): DateReachedTriggerSource
    {
        return app(DateReachedTriggerSource::class);
    }

    /**
     * A published contact_date_reached workflow over one group and date field.
     */
    private function publishDateWorkflow(
        Business $business,
        ContactGroups $group,
        ContactGroupFields $field,
        array $configOverrides = [],
        string $name = 'Birthday',
    ): AutomationWorkflow {
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, $name, WorkflowTriggerType::ContactDateReached);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition(WorkflowTriggerType::ContactDateReached);
        $definition['root']['config'] = array_merge($definition['root']['config'], [
            'contact_group_id' => $group->id,
            'date_field_id' => $field->id,
            'offset' => '0 day',
            'send_at' => '09:00',
        ], $configOverrides);
        $definition['root']['next'] = [$this->endStep()];

        $drafts->autosave($draft, $definition, $draft->definition_revision);
        app(WorkflowPublisher::class)->publish($workflow->fresh());

        return $workflow->fresh();
    }

    /** @return array{0: Business, 1: ContactGroups, 2: ContactGroupFields} */
    private function dateTenant(string $timezone = 'UTC'): array
    {
        [$customer, $business] = $this->entitledTenant();
        DB::table('businesses')->where('id', $business->id)->update(['timezone' => $timezone]);
        $business = $business->fresh();
        $group = $this->contactGroup($business);
        $field = $this->dateField($group);

        return [$business, $group, $field];
    }

    // -----------------------------------------------------------------
    // 9-11 — due, not due, and the exact boundary
    // -----------------------------------------------------------------

    public function test_a_due_workflow_enrolls_the_contact_and_starts_the_journey(): void
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        [$business, $group, $field] = $this->dateTenant();
        $workflow = $this->publishDateWorkflow($business, $group, $field);
        $contact = $this->contact($business, $group, '12025551101', '1990-06-15', $field);

        $result = $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100);

        $this->assertSame(1, $result['enrolled']);
        $enrollment = AutomationEnrollment::query()->sole();
        $this->assertSame((int) $contact->getKey(), (int) $enrollment->contact_id);
        $this->assertSame('2026', $enrollment->trigger_occurrence_key, 'The claim is the occurrence year.');
        Bus::assertDispatched(AdvanceWorkflowEnrollment::class, 1);
    }

    public function test_a_future_date_does_not_enroll(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field);
        $this->contact($business, $group, '12025551102', '1990-06-15', $field);

        $result = $this->trigger()->sweep(CarbonImmutable::parse('2026-06-14 09:00:00', 'UTC'), 100);

        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_the_send_at_boundary_is_pinned_exactly(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field, ['send_at' => '09:00']);
        $this->contact($business, $group, '12025551103', '1990-06-15', $field);

        // One second before send_at: not due.
        $this->assertSame(0, $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 08:59:59', 'UTC'), 100)['enrolled']);
        $this->assertSame(0, AutomationEnrollment::query()->count());

        // Exactly at send_at: due. Half-open, with the boundary itself inside.
        $this->assertSame(1, $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100)['enrolled']);
    }

    public function test_the_business_timezone_governs_the_local_day_not_the_server(): void
    {
        [$business, $group, $field] = $this->dateTenant('Pacific/Auckland');
        $this->publishDateWorkflow($business, $group, $field, ['send_at' => '09:00']);
        $this->contact($business, $group, '12025551104', '1990-06-15', $field);

        // 2026-06-14 21:00 UTC is 2026-06-15 09:00 in Auckland: due there,
        // still the 14th in UTC.
        $this->assertSame(1, $this->trigger()->sweep(CarbonImmutable::parse('2026-06-14 21:00:00', 'UTC'), 100)['enrolled']);
        $this->assertSame('2026', AutomationEnrollment::query()->sole()->trigger_occurrence_key);
    }

    public function test_the_offset_shifts_the_occurrence_and_its_year(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        // Seven days ahead of the stored date: on 25 December the sweep is
        // looking for 1 January, whose year is the NEXT one — which is exactly
        // why the key is the shifted date's year, not "now"'s.
        $this->publishDateWorkflow($business, $group, $field, ['offset' => '1 week']);
        $this->contact($business, $group, '12025551105', '1990-01-01', $field);

        $this->assertSame(1, $this->trigger()->sweep(CarbonImmutable::parse('2026-12-25 09:00:00', 'UTC'), 100)['enrolled']);
        $this->assertSame('2027', AutomationEnrollment::query()->sole()->trigger_occurrence_key);
    }

    public function test_a_leap_day_contact_is_only_due_on_the_leap_day(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field);
        $this->contact($business, $group, '12025551106', '1992-02-29', $field);

        // 2026 has no 29 February, so nothing matches — the month-day rule is
        // B4's, and this slice does not invent a substitute date.
        $this->assertSame(0, $this->trigger()->sweep(CarbonImmutable::parse('2026-02-28 09:00:00', 'UTC'), 100)['enrolled']);
        $this->assertSame(0, $this->trigger()->sweep(CarbonImmutable::parse('2026-03-01 09:00:00', 'UTC'), 100)['enrolled']);

        // 2028 does.
        $this->assertSame(1, $this->trigger()->sweep(CarbonImmutable::parse('2028-02-29 09:00:00', 'UTC'), 100)['enrolled']);
    }

    // -----------------------------------------------------------------
    // 12-14 — idempotence, forward progress, bounded queries
    // -----------------------------------------------------------------

    public function test_repeating_the_sweep_creates_no_duplicate(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field);
        $this->contact($business, $group, '12025551107', '1990-06-15', $field);
        $now = CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC');

        $this->assertSame(1, $this->trigger()->sweep($now, 100)['enrolled']);

        // Every five minutes for the rest of the day, and twice at once from
        // two servers: the year claim is unique, so nobody enrolls twice.
        foreach ([0, 5, 10, 600] as $minutes) {
            $this->assertSame(0, $this->trigger()->sweep($now->addMinutes($minutes), 100)['enrolled']);
        }

        $this->assertSame(1, AutomationEnrollment::query()->count());
    }

    public function test_the_next_year_is_a_new_occurrence(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field);
        $this->contact($business, $group, '12025551108', '1990-06-15', $field);

        $this->assertSame(1, $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100)['enrolled']);

        // Complete the first journey so the active-contact guard does not stand
        // in for the policy; the point here is that the KEY differs by year.
        DB::table('automation_enrollments')->update(['status' => 'completed', 'completed_at' => now()]);

        $this->assertSame(1, $this->trigger()->sweep(CarbonImmutable::parse('2027-06-15 09:00:00', 'UTC'), 100)['enrolled']);
        $this->assertSame(['2026', '2027'], AutomationEnrollment::query()->orderBy('id')->pluck('trigger_occurrence_key')->all());
    }

    /**
     * A date workflow is `once_per_occurrence` by default, but the owner may
     * deliberately make one `once_ever` — a single "one year with us" message
     * rather than a yearly one (§7.5, allowed only as a USER choice). The sweep
     * must then respect a key that carries no occurrence at all, which is why it
     * reads the published version's real policy instead of assuming the default.
     */
    public function test_a_deliberate_once_ever_date_workflow_enrolls_a_contact_only_once(): void
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field, [
            'enrollment_policy' => EnrollmentPolicy::OnceEver->value,
            'enrollment_policy_source' => EnrollmentPolicySource::User->value,
        ]);
        $this->contact($business, $group, '12025557001', '1990-06-15', $field);

        $this->assertSame(1, $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100)['enrolled']);

        DB::table('automation_enrollments')->update(['status' => 'completed', 'completed_at' => now()]);

        // Next June is a new occurrence, but not a new entry: this workflow was
        // set to once ever.
        $this->assertSame(0, $this->trigger()->sweep(CarbonImmutable::parse('2027-06-15 09:00:00', 'UTC'), 100)['enrolled']);
        $this->assertSame(1, AutomationEnrollment::query()->count());
    }

    public function test_a_capped_run_makes_real_forward_progress(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field);

        foreach (range(1, 5) as $index) {
            $this->contact($business, $group, '1202555120' . $index, '1990-06-15', $field);
        }

        $now = CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC');

        // Two at a time: each run enrolls the NEXT two, never the same two,
        // because already-enrolled contacts are excluded in SQL by their key.
        $this->assertSame(2, $this->trigger()->sweep($now, 2)['enrolled']);
        $this->assertSame(2, $this->trigger()->sweep($now, 2)['enrolled']);
        $this->assertSame(1, $this->trigger()->sweep($now, 2)['enrolled']);
        $this->assertSame(0, $this->trigger()->sweep($now, 2)['enrolled']);

        $this->assertSame(5, AutomationEnrollment::query()->count());
        $this->assertSame(5, AutomationEnrollment::query()->distinct()->count('contact_id'));
    }

    public function test_the_sweep_query_count_does_not_grow_with_the_contact_list(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field);
        $now = CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC');

        DB::listen(function (): void {
            $this->sweptQueries++;
        });

        foreach (range(1, 3) as $index) {
            $this->contact($business, $group, '1202555130' . $index, '1990-06-15', $field);
        }

        $this->sweptQueries = 0;
        $this->trigger()->sweep($now, 1);
        $withThree = $this->sweptQueries;

        foreach (range(4, 9) as $index) {
            $this->contact($business, $group, '1202555130' . $index, '1990-06-15', $field);
        }

        $this->sweptQueries = 0;
        $this->trigger()->sweep($now, 1);
        $withNine = $this->sweptQueries;

        $this->assertGreaterThan(0, $withThree);
        $this->assertSame($withThree, $withNine, 'A bounded sweep reads a page, not the table.');
    }

    /**
     * More due contacts than one page, swept in one run.
     *
     * Two things at once: the contact read is paged at SWEEP_CHUNK_SIZE (so a
     * group of any size never becomes one enormous result set), and the keyset
     * on contacts.id carries the run past the first page instead of re-reading
     * it — which is also why the loop cannot spin.
     */
    public function test_more_due_contacts_than_one_page_are_all_swept_in_one_run(): void
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field);

        $total = WorkflowLimits::SWEEP_CHUNK_SIZE + 3;

        foreach (range(1, $total) as $index) {
            $this->contact($business, $group, '1202556' . str_pad((string) $index, 4, '0', STR_PAD_LEFT), '1990-06-15', $field);
        }

        $result = $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 500);

        $this->assertSame($total, $result['enrolled'], 'A page boundary is not the end of the run.');
        $this->assertSame($total, AutomationEnrollment::query()->distinct()->count('contact_id'));

        // And a second run over the same occurrence adds nobody.
        $this->assertSame(0, $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:30:00', 'UTC'), 500)['enrolled']);
    }

    public function test_the_sweep_walks_workflows_in_bounded_keyset_pages(): void
    {
        [$business, $group, $field] = $this->dateTenant();

        // More workflows than one page, each with its own due contact.
        $pageSize = WorkflowLimits::SWEEP_CHUNK_SIZE;
        $this->assertGreaterThan(1, $pageSize);

        foreach (range(1, 3) as $index) {
            $this->publishDateWorkflow($business, $group, $field, [], 'Birthday ' . $index);
        }

        $this->contact($business, $group, '12025551401', '1990-06-15', $field);

        $result = $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100);

        // One contact, three listening workflows: three enrollments, and the
        // workflow walk is capped per page by SWEEP_CHUNK_SIZE.
        $this->assertSame(3, $result['enrolled']);
        $this->assertSame(3, $result['considered']);
    }

    // -----------------------------------------------------------------
    // 15-16 — ineligible workflows, and tenancy
    // -----------------------------------------------------------------

    public function test_a_paused_workflow_does_not_enroll(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $workflow = $this->publishDateWorkflow($business, $group, $field);
        $this->contact($business, $group, '12025551501', '1990-06-15', $field);

        DB::table('automation_workflows')->where('id', $workflow->getKey())->update(['status' => 'paused']);

        $this->assertSame(0, $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100)['enrolled']);
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_a_draft_only_workflow_does_not_enroll(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'Never published', WorkflowTriggerType::ContactDateReached);
        $draft = $workflow->draftVersion();
        $definition = $drafts->starterDefinition(WorkflowTriggerType::ContactDateReached);
        $definition['root']['config'] = array_merge($definition['root']['config'], [
            'contact_group_id' => $group->id,
            'date_field_id' => $field->id,
            'offset' => '0 day',
            'send_at' => '09:00',
        ]);
        $drafts->autosave($draft, $definition, $draft->definition_revision);

        $this->contact($business, $group, '12025551502', '1990-06-15', $field);

        $this->assertSame(0, $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100)['enrolled']);
    }

    /**
     * V2-0's validator refuses to PUBLISH an incomplete or disallowed date
     * config (NodeTypeRegistry::validateTriggerSpecifics), so these shapes can
     * only reach the sweep as a legacy or tampered row. The sweep still refuses
     * them rather than guessing a date — two independent guards, which is what
     * keeps a bad row from becoming a message.
     */
    public function test_an_incomplete_or_disallowed_date_config_enrolls_nobody(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $this->contact($business, $group, '12025551503', '1990-06-15', $field);

        $tampered = [
            'no field' => ['date_field_id' => 0],
            'no group' => ['contact_group_id' => 0],
            'offset outside the allowlist' => ['offset' => '3 weeks'],
            'unparseable time' => ['send_at' => 'noon'],
        ];

        foreach ($tampered as $label => $override) {
            $workflow = $this->publishDateWorkflow($business, $group, $field, [], 'Legacy ' . $label);

            // Publish a valid config, then tamper the compiled row.
            $node = DB::table('automation_workflow_nodes')
                ->where('version_id', $workflow->published_version_id)
                ->where('node_type', 'trigger')
                ->first();
            $config = json_decode($node->config, true);

            DB::table('automation_workflow_nodes')
                ->where('id', $node->id)
                ->update(['config' => json_encode(array_merge($config, $override))]);

            $this->assertSame(
                0,
                $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100)['enrolled'],
                'A workflow with ' . $label . ' must enroll nobody.',
            );
            $this->assertSame(0, AutomationEnrollment::query()->where('workflow_id', $workflow->getKey())->count());

            DB::table('automation_workflows')->where('id', $workflow->getKey())->update(['status' => 'archived']);
        }
    }

    public function test_the_offset_vocabulary_is_v2_0s_not_this_slices(): void
    {
        $this->assertSame(
            \App\Library\Automation\Workflow\NodeTypeRegistry::DATE_OFFSET_ALLOWLIST,
            DateReachedTriggerSource::offsetAllowlist(),
            'A publishable offset and a sweepable one must be the same list.',
        );
    }

    public function test_an_unsubscribed_contact_is_not_enrolled(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field);
        $contact = $this->contact($business, $group, '12025551504', '1990-06-15', $field);
        DB::table('contacts')->where('id', $contact->getKey())->update(['status' => 'unsubscribe']);

        $this->assertSame(0, $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100)['enrolled']);
    }

    public function test_a_rival_businesss_contacts_are_excluded(): void
    {
        [$businessOne, $groupOne, $fieldOne] = $this->dateTenant();
        [$businessTwo, $groupTwo, $fieldTwo] = $this->dateTenant();

        $workflow = $this->publishDateWorkflow($businessOne, $groupOne, $fieldOne);
        $mine = $this->contact($businessOne, $groupOne, '12025551601', '1990-06-15', $fieldOne);
        $theirs = $this->contact($businessTwo, $groupTwo, '12025551602', '1990-06-15', $fieldTwo);

        $result = $this->trigger()->sweep(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'), 100);

        $this->assertSame(1, $result['enrolled']);
        $enrollment = AutomationEnrollment::query()->where('workflow_id', $workflow->getKey())->sole();
        $this->assertSame((int) $mine->getKey(), (int) $enrollment->contact_id);
        $this->assertSame(0, AutomationEnrollment::query()->where('contact_id', $theirs->getKey())->count());
    }

    // -----------------------------------------------------------------
    // The command around it
    // -----------------------------------------------------------------

    public function test_the_command_sweeps_and_reports(): void
    {
        [$business, $group, $field] = $this->dateTenant();
        $this->publishDateWorkflow($business, $group, $field);
        $this->contact($business, $group, '12025551701', '1990-06-15', $field);

        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'));

        $this->artisan('automation:workflows-date-sweep')
            ->expectsOutputToContain('enrolled 1 contact(s)')
            ->assertSuccessful();

        // Twice in one tick changes nothing.
        $this->artisan('automation:workflows-date-sweep')
            ->expectsOutputToContain('enrolled 0 contact(s)')
            ->assertSuccessful();

        $this->travelBack();
    }

    public function test_the_command_rejects_a_nonsense_limit(): void
    {
        $this->artisan('automation:workflows-date-sweep', ['--limit' => '0'])->assertExitCode(2);
        $this->artisan('automation:workflows-date-sweep', ['--limit' => 'lots'])->assertExitCode(2);
    }

    public function test_the_command_defaults_to_the_canonical_sweep_bound(): void
    {
        $this->assertSame(2000, WorkflowLimits::SWEEP_ENROLLMENTS_PER_RUN, 'The default bound is V2-0s, not this slices invention.');
    }

    public function test_the_sweep_is_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'automation:workflows-date-sweep'));

        $this->assertCount(1, $events, 'A date trigger needs something to look for it.');
        $this->assertSame('*/5 * * * *', $events->first()->expression);
    }

    public function test_the_trigger_carries_the_once_per_occurrence_policy(): void
    {
        $this->assertSame(EnrollmentPolicy::OncePerOccurrence, DateReachedTriggerSource::expectedPolicy());
    }
}
