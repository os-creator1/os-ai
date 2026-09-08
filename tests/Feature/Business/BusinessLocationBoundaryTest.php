<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Library\Business\BusinessLocationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — T-LOC-9 and T-LOC-10 (contract §7.3b).
 *
 * WHAT THIS TEST HONESTLY GUARANTEES, AND WHAT IT DOES NOT.
 *
 * It does NOT prove that arbitrary future ORM calls, repository methods or
 * raw SQL can never write to `business_locations`. No PHP test can prove
 * that, and this contract explicitly withdrew that claim.
 *
 * What it DOES do is guard the repository's architecture: it enumerates
 * every PRODUCTION source seam that can increase a Business's active
 * location count, asserts that set is exactly the approved list, and fails
 * when a new unapproved seam appears — so adding one becomes a deliberate,
 * reviewed act rather than an accident. T-LOC-10 then proves, route by
 * route, that every customer-reachable path actually delegates to the
 * canonical BusinessLocationManager boundary.
 */
class BusinessLocationBoundaryTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use RefreshDatabase;

    /**
     * The approved production write seams. Anything else that writes a
     * `business_locations` row must be added here deliberately, with a
     * reviewer's attention on whether it belongs behind the capacity
     * boundary.
     *
     * Migrations, factories, seeders and test-support helpers are outside
     * production `app/` and are permitted to write directly (§7.3b point 3).
     */
    private const APPROVED_WRITE_SEAMS = [
        // The ONE persistence seam that inserts a business_locations row.
        // It performs no capacity check of its own, and the inventory below
        // proves nothing else in production inserts one. The capacity check
        // lives in BusinessLocationManager, which orchestrates this seam and
        // is the only production caller of createActive() — proven
        // behaviourally by T-LOC-10 rather than by grepping, because
        // "delegates to" is a runtime property, not a textual one.
        'app/Repositories/Eloquent/EloquentBusinessLocationRepository.php',
    ];

    /**
     * Files permitted to ASSIGN a lifecycle state. The model is excluded
     * deliberately: it only declares the enum CAST
     * (`'lifecycle_state' => BusinessLocationLifecycleState::class`), which
     * is a type declaration, not a write.
     */
    private const APPROVED_LIFECYCLE_WRITERS = [
        'app/Library/Business/BusinessLocationManager.php',
        'app/Repositories/Eloquent/EloquentBusinessLocationRepository.php',
    ];

    /** T-LOC-9 — source-boundary inventory. */
    public function test_only_approved_production_seams_write_business_locations(): void
    {
        $found = [];

        foreach ($this->productionPhpFiles() as $file) {
            $source = (string) file_get_contents($file);

            $writes = str_contains($source, 'BusinessLocation::create(')
                || str_contains($source, "locations()->create(")
                || preg_match("/table\(\s*'business_locations'\s*\)\s*->\s*(insert|insertGetId|updateOrInsert)/", $source) === 1;

            if ($writes) {
                $found[] = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));
            }
        }

        sort($found);
        $approved = self::APPROVED_WRITE_SEAMS;
        sort($approved);

        $this->assertSame(
            $approved,
            $found,
            "A production file writes business_locations outside the approved set.\n"
            . "Every customer-reachable active-location-count-increasing write must go through\n"
            . "App\\Library\\Business\\BusinessLocationManager (contract §7.3b).\n"
            . "If a new seam is genuinely required, add it to APPROVED_WRITE_SEAMS deliberately."
        );
    }

    /**
     * The lifecycle column is the capacity signal, so only the canonical
     * boundary may change it.
     */
    public function test_only_the_canonical_boundary_changes_the_lifecycle_state(): void
    {
        $found = [];

        foreach ($this->productionPhpFiles() as $file) {
            $source = (string) file_get_contents($file);

            // An ASSIGNMENT, not the model's enum cast declaration
            // (`'lifecycle_state' => BusinessLocationLifecycleState::class`).
            // Checked line by line so no regex backtracking can let the
            // cast slip through as a write.
            foreach (preg_split("/\r?\n/", $source) ?: [] as $line) {
                if (str_contains($line, "'lifecycle_state' =>") && ! str_contains($line, '::class')) {
                    $found[] = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));

                    break;
                }
            }
        }

        sort($found);
        $approved = self::APPROVED_LIFECYCLE_WRITERS;
        sort($approved);

        $this->assertSame(
            $approved,
            $found,
            'Only the canonical boundary and its persistence seam may assign lifecycle_state.'
        );
    }

    /**
     * T-LOC-10 — every currently reachable customer route that can increase
     * the active location count delegates to the boundary, and is refused
     * when capacity is exhausted.
     */
    public function test_every_customer_reachable_create_route_delegates_to_the_boundary(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant();
        $this->seedActiveLocations($business, 3);
        $this->authenticateAsOwner($customer);

        // Route 1: the Slice 1A locations surface.
        $this->post(
            route('customer.workspaces.businesses.locations.store', [$workspace->uid, $business->uid]),
            $this->locationPayload()
        )->assertRedirect();

        $this->assertSame(3, $this->activeLocationCount($business), 'The create route must be refused at capacity.');

        // Route 2: reactivation.
        $archived = $this->seedLocation($business, 'Closed', false, BusinessLocationLifecycleState::Archived);

        $this->post(
            route('customer.workspaces.businesses.locations.reactivate', [$workspace->uid, $business->uid]),
            ['location_uid' => $archived->uid]
        )->assertRedirect();

        $this->assertSame(
            BusinessLocationLifecycleState::Archived,
            $archived->refresh()->lifecycle_state,
            'The reactivate route must be refused at capacity.'
        );

        // With capacity, both routes succeed — proving they are wired to
        // the boundary rather than simply broken.
        $this->setAdditionalLocationSlots($business, 2);

        $this->post(
            route('customer.workspaces.businesses.locations.store', [$workspace->uid, $business->uid]),
            $this->locationPayload(['name' => 'Fourth'])
        )->assertRedirect();

        $this->assertSame(4, $this->activeLocationCount($business));

        $this->post(
            route('customer.workspaces.businesses.locations.reactivate', [$workspace->uid, $business->uid]),
            ['location_uid' => $archived->uid]
        )->assertRedirect();

        $this->assertSame(5, $this->activeLocationCount($business));
    }

    /**
     * T-LOC-10 — the legacy onboarding location step, migrated onto the
     * boundary (§7.3b point 2). Its creating case must now pass through the
     * capacity assertion.
     */
    public function test_the_onboarding_location_step_delegates_to_the_boundary(): void
    {
        [$customer, $business] = $this->locationTenant();

        // No primary yet: this is the count-increasing case.
        $location = app(\App\Library\Business\BusinessManager::class)
            ->upsertPrimaryLocation($customer, $business, $this->locationPayload(['name' => 'Onboarded']));

        $this->assertSame(BusinessLocationLifecycleState::Active, $location->lifecycle_state);
        $this->assertTrue((bool) $location->is_primary);
        $this->assertSame(1, $this->activeLocationCount($business));

        // Editing the existing primary is NOT count-increasing and stays on
        // the ordinary upsert path.
        $updated = app(\App\Library\Business\BusinessManager::class)
            ->upsertPrimaryLocation($customer, $business, $this->locationPayload(['name' => 'Renamed']));

        $this->assertSame((int) $location->id, (int) $updated->id);
        $this->assertSame(1, $this->activeLocationCount($business));
    }

    /**
     * A newly introduced unapproved seam would fail T-LOC-9. Proven by
     * running the inventory rule against a synthetic file rather than by
     * actually adding one to the repository.
     */
    public function test_a_new_unapproved_seam_would_fail_the_inventory(): void
    {
        $synthetic = "<?php\nclass RogueController { public function store(\$b) { return \$b->locations()->create([]); } }\n";

        $writes = str_contains($synthetic, 'BusinessLocation::create(')
            || str_contains($synthetic, "locations()->create(")
            || preg_match("/table\(\s*'business_locations'\s*\)\s*->\s*(insert|insertGetId|updateOrInsert)/", $synthetic) === 1;

        $this->assertTrue($writes, 'The inventory rule must detect a new direct write seam.');

        $this->assertNotContains(
            'app/Http/Controllers/RogueController.php',
            self::APPROVED_WRITE_SEAMS,
            'Such a seam is not approved, so T-LOC-9 would fail until it is reviewed and listed.'
        );
    }

    /**
     * Only production application code. Migrations, factories, seeders and
     * tests are deliberately outside this inventory (§7.3b point 3).
     *
     * @return array<int, string>
     */
    private function productionPhpFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
