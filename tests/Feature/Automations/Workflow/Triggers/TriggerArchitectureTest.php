<?php

namespace Tests\Feature\Automations\Workflow\Triggers;

use App\Enums\Automation\Workflow\ContactCreationSource;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Contracts\TriggerSource;
use App\Library\Automation\Workflow\Runtime\WorkflowEnrollmentService;
use App\Library\Automation\Workflow\Triggers\ContactCreatedTriggerSource;
use App\Library\Automation\Workflow\Triggers\DateReachedTriggerSource;
use App\Library\Automation\Workflow\Triggers\ManualEnrollmentTriggerSource;
use App\Library\Automation\Workflow\Triggers\TriggerSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Automations V2-C — the boundaries this slice must not cross.
 *
 * EnrollmentService is the only door into a workflow (§7.5). A trigger source
 * that wrote an enrollment row itself, or re-decided eligibility, would be a
 * second authority — and the duplicates and cross-tenant journeys that follow
 * are exactly what concentrating it prevents. These are source-level proofs
 * because that is the only way to show the absence of a shortcut.
 */
class TriggerArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private const TRIGGER_SOURCES = [
        'app/Library/Automation/Workflow/Triggers/ContactCreatedTriggerSource.php',
        'app/Library/Automation/Workflow/Triggers/DateReachedTriggerSource.php',
        'app/Library/Automation/Workflow/Triggers/ManualEnrollmentTriggerSource.php',
    ];

    // 22 — every source enrolls through the canonical service
    public function test_every_trigger_source_depends_on_the_enrollment_service(): void
    {
        foreach ([
            ContactCreatedTriggerSource::class,
            DateReachedTriggerSource::class,
            ManualEnrollmentTriggerSource::class,
        ] as $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();
            $this->assertNotNull($constructor, $class . ' must take its dependencies explicitly.');

            $types = array_map(
                fn (\ReflectionParameter $parameter) => (string) $parameter->getType(),
                $constructor->getParameters(),
            );

            $this->assertContains(
                EnrollmentService::class,
                $types,
                $class . ' must enroll through the canonical EnrollmentService.',
            );
        }
    }

    public function test_the_enrollment_contract_still_resolves_to_the_runtime_implementation(): void
    {
        $this->assertInstanceOf(WorkflowEnrollmentService::class, app(EnrollmentService::class));
    }

    // 23 — no trigger source writes an enrollment itself
    public function test_no_trigger_source_writes_the_enrollment_table_directly(): void
    {
        foreach (self::TRIGGER_SOURCES as $path) {
            $source = file_get_contents(base_path($path));

            foreach ([
                'AutomationEnrollment::create',
                'AutomationEnrollment::insert',
                'new AutomationEnrollment',
                "table('automation_enrollments')->insert",
                "table('automation_enrollments')->update",
                "table('automation_enrollments')->updateOrInsert",
                "table('automation_enrollments')->insertOrIgnore",
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    $path . ' must not create or mutate an enrollment; EnrollmentService owns that row.',
                );
            }
        }
    }

    public function test_no_trigger_source_invents_an_enrollment_key_format(): void
    {
        foreach (self::TRIGGER_SOURCES as $path) {
            $source = file_get_contents(base_path($path));

            // The SHAPE of the key belongs to EnrollmentPolicy (§7.5). A source
            // that spelled one out would keep matching until the day the format
            // changed, and then quietly match nothing.
            foreach (["'wf:", '"wf:', "':c:", "':o:", ":c:%d", ":o:%s"] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    $path . ' must not spell out an enrollment key format.',
                );
            }
        }

        // The date sweep DOES need a key: excluding already-enrolled contacts in
        // SQL is what makes a capped run progress. So it asks the canonical
        // composer for one. Reading a key is allowed; owning its shape is not.
        $date = file_get_contents(base_path('app/Library/Automation/Workflow/Triggers/DateReachedTriggerSource.php'));
        $this->assertStringContainsString(
            '->enrollmentKey(',
            $date,
            'The sweep must ask EnrollmentPolicy for the key, not assemble one.',
        );

        // And the other two have no reason to know a key at all.
        foreach ([
            'app/Library/Automation/Workflow/Triggers/ContactCreatedTriggerSource.php',
            'app/Library/Automation/Workflow/Triggers/ManualEnrollmentTriggerSource.php',
        ] as $path) {
            $this->assertStringNotContainsString(
                'enrollmentKey',
                file_get_contents(base_path($path)),
                $path . ' has no business with enrollment keys.',
            );
        }
    }

    public function test_no_trigger_source_decides_published_version_selection(): void
    {
        foreach (self::TRIGGER_SOURCES as $path) {
            $source = file_get_contents(base_path($path));

            // Selecting and pinning the version is the service's job. A source
            // that read published_version_id to DECIDE would be able to pin a
            // stale one; joining on it to FIND listening workflows is a filter,
            // not a decision, so the join is allowed and the assignment is not.
            $this->assertStringNotContainsString('version_id =', $source, $path . ' must not choose a version.');
            $this->assertStringNotContainsString("'version_id' =>", $source, $path . ' must not set a version.');
        }
    }

    // The registry, wired the canonical way
    public function test_the_three_sources_are_registered_for_their_trigger_types(): void
    {
        $registry = app(TriggerSourceRegistry::class);

        $expected = [
            WorkflowTriggerType::ContactCreated->value => ContactCreatedTriggerSource::class,
            WorkflowTriggerType::ContactDateReached->value => DateReachedTriggerSource::class,
            WorkflowTriggerType::ManualEnrollment->value => ManualEnrollmentTriggerSource::class,
        ];

        foreach ($expected as $type => $class) {
            $source = $registry->for(WorkflowTriggerType::from($type));

            $this->assertInstanceOf($class, $source, $type . ' must have its source registered.');
            $this->assertInstanceOf(TriggerSource::class, $source);
            $this->assertTrue($registry->available(WorkflowTriggerType::from($type)));
        }

        $this->assertSame(array_keys($expected), $registry->registeredTypes());
    }

    public function test_message_received_has_no_source_until_its_own_slice_ships(): void
    {
        $registry = app(TriggerSourceRegistry::class);

        // V2-F owns it. Reporting it unavailable is what keeps the validator's
        // refusal to publish such a workflow honest.
        $this->assertNull($registry->for(WorkflowTriggerType::MessageReceived));
        $this->assertFalse($registry->available(WorkflowTriggerType::MessageReceived));
        $this->assertFalse(WorkflowTriggerType::MessageReceived->isIngestableInThisSlice());
    }

    public function test_the_registry_is_a_singleton_so_registrations_are_shared(): void
    {
        $this->assertSame(app(TriggerSourceRegistry::class), app(TriggerSourceRegistry::class));
    }

    // 25 — B4 is still here
    public function test_b4_remains_present_and_dispatched_beside_the_v2_trigger(): void
    {
        $this->assertTrue(class_exists(\App\Jobs\AutomationJob::class), 'B4 stays live until V2-G retires it.');
        $this->assertTrue(class_exists(\App\Library\Automation\AutomationTriggerEvaluator::class));

        $repository = file_get_contents(base_path('app/Repositories/Eloquent/EloquentContactsRepository.php'));

        // Both dispatches, at both seams: two B4 and two V2, each still
        // guarded by an explicit business_id check.
        $this->assertSame(2, substr_count($repository, 'AutomationJob::forContactCreated'), 'B4 keeps both of its dispatches.');
        $this->assertSame(2, substr_count($repository, 'EnrollWorkflowContact::forContactCreated'), 'V2 dispatches beside each of them.');
    }

    public function test_the_creation_source_vocabulary_is_closed_and_has_no_fictional_case(): void
    {
        $this->assertSame(
            ['manual', 'opt_in_form', 'api', 'other'],
            array_map(fn (ContactCreationSource $case) => $case->value, ContactCreationSource::cases()),
        );

        // No `import` case: bulk import passes through neither creation seam, so
        // a filter naming it could never fire. See ContactCreatedTriggerTest's
        // import proof.
        $this->assertNull(ContactCreationSource::tryFrom('import'));
    }

    public function test_every_creation_seam_caller_declares_its_source(): void
    {
        $callers = [
            'app/Http/Controllers/API/ContactsController.php' => ContactCreationSource::Api,
            'app/Http/Controllers/API/ContactsHTTPController.php' => ContactCreationSource::Api,
        ];

        foreach ($callers as $path => $expected) {
            $source = file_get_contents(base_path($path));
            $this->assertStringContainsString(
                'ContactCreationSource::' . ucfirst($expected->value),
                $source,
                $path . ' must declare its creation source explicitly.',
            );
        }

        // The customer controller holds two different paths, and they must
        // declare DIFFERENT sources: the in-app form is not an opt-in.
        $customer = file_get_contents(base_path('app/Http/Controllers/Customer/ContactsController.php'));
        $this->assertStringContainsString('ContactCreationSource::Manual', $customer);
        $this->assertStringContainsString('ContactCreationSource::OptInForm', $customer);
        $this->assertSame(
            1,
            substr_count($customer, 'ContactCreationSource::OptInForm'),
            'Exactly one path in this controller is a genuine opt-in.',
        );
    }

    public function test_no_trigger_source_infers_the_creation_source(): void
    {
        $source = file_get_contents(base_path('app/Library/Automation/Workflow/Triggers/ContactCreatedTriggerSource.php'));

        // The source is an argument. Anything that sniffed the request, the
        // route or the contact's own fields would be an inference.
        foreach ([
            'request(',
            'Route::',
            'url(',
            '->routeIs(',
            'referer',
            'user_agent',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, 'The creation source must never be inferred.');
        }
    }
}
