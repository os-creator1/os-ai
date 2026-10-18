<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoReviewRequestStatus;
use App\Models\SeoReviewRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Seo\Concerns\CreatesSeoReviewFixtures;
use Tests\TestCase;

/**
 * Regression: a legacy/hand-seeded `resolved` status row crashed the Reviews
 * page ("resolved" is not a valid backing value for SeoReviewRequestStatus).
 * The page must always load, and the repair migration maps `resolved` to
 * `reviewed` without touching any valid row or inventing a rating.
 */
class SeoReviewLegacyStatusRepairTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bypassReviewEntitlementForTest();
    }

    private function runRepairMigration(): void
    {
        (require base_path('database/migrations/2026_10_26_100001_repair_legacy_resolved_seo_review_request_status.php'))->up();
    }

    public function test_the_contracted_status_values_are_unchanged(): void
    {
        $this->assertSame(['requested', 'reviewed', 'declined'], array_map(fn ($case) => $case->value, SeoReviewRequestStatus::cases()));
    }

    public function test_the_reviews_page_loads_when_a_row_holds_a_non_contract_status(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->reviewLocation($business, 'Main Storefront');
        $this->authenticateAsSeoCustomer($customer, $this->reviewPermissions());

        $request = $this->makeReviewRequest($business, $location, $this->reviewContact($business, $location));
        DB::table('seo_review_requests')->where('id', $request->id)->update(['status' => 'resolved', 'resolved_at' => now()]);

        $html = $this->get($this->reviewsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-status="unknown"', $html);
        $this->assertStringNotContainsString('data-role="mark-reviewed-form"', $html, 'An unrecognised row offers no outcome action.');
    }

    public function test_the_migration_maps_resolved_to_reviewed_only_and_is_idempotent(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->reviewLocation($business, 'Main Storefront');

        $legacy = $this->makeReviewRequest($business, $location, $this->reviewContact($business, $location));
        $requested = $this->makeReviewRequest($business, $location, $this->reviewContact($business, $location, ['FIRST_NAME' => 'Sam', 'LAST_NAME' => 'Lee']));
        $declined = $this->makeReviewRequest($business, $location, null, ['status' => SeoReviewRequestStatus::Declined->value]);

        $resolvedAt = now()->subDay()->startOfSecond();
        DB::table('seo_review_requests')->where('id', $legacy->id)->update(['status' => 'resolved', 'resolved_at' => $resolvedAt]);

        $this->runRepairMigration();
        $this->runRepairMigration();

        $legacy = SeoReviewRequest::query()->findOrFail($legacy->id);
        $this->assertSame(SeoReviewRequestStatus::Reviewed, $legacy->status);
        $this->assertTrue($legacy->resolved_at->equalTo($resolvedAt), 'resolved_at is left exactly as stored.');
        $this->assertSame(SeoReviewRequestStatus::Requested, $requested->fresh()->status);
        $this->assertSame(SeoReviewRequestStatus::Declined, $declined->fresh()->status);
    }
}
