<?php

namespace Tests\Feature\Website;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Website\WebsiteAiGenerationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * "Suggest description" in the Website page editor: owner-initiated, metered through the existing website AI client, and read-only —
 * it never writes the draft and never publishes.
 */
class WebsiteMetaDescriptionSuggestionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private const GOOD = 'Plan a memorable event with our photo booth rentals. See what is included and how to book your date.';

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: \App\Models\WebsitePage} */
    private function growthTenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Suggestion test.');
        $page = $this->homePage($this->createWebsite($business), ['title' => 'Photo Booth Rentals']);

        return [$customer, $business->fresh(), $workspace->fresh(), $page];
    }

    private function suggest($workspace, $business, $page)
    {
        return $this->postJson(route('customer.workspaces.businesses.website.pages.suggestDescription', [$workspace->uid, $business->uid, $page->uid]));
    }

    private function ai(?string $json, bool $budget = false, int $calls = 1): void
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn($budget);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->times($calls)->withArgs(fn (array $messages) => str_contains($messages[1]['content'], 'Photo Booth Rentals'))->andReturn($json);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    public function test_a_suggestion_is_returned_for_review_and_nothing_is_written_or_published(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $this->ai(json_encode(['description' => self::GOOD]));
        $website = $page->website;

        $this->suggest($workspace, $business, $page)->assertOk()->assertJson(['ok' => true, 'description' => self::GOOD]);

        $this->assertNull($page->fresh()->meta_description, 'The draft is not overwritten.');
        $this->assertNull($website->fresh()->published_revision_id, 'Nothing is published.');
    }

    public function test_opening_the_editor_makes_no_ai_request(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $this->ai(null, false, 0);

        $this->get(route('customer.workspaces.businesses.website.pages.edit', [$workspace->uid, $business->uid, $page->uid]))
            ->assertOk()->assertSee('Suggest description');
    }

    public function test_someone_without_website_permission_is_refused_and_no_ai_is_called(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer, ['view_seo']);
        $this->ai(json_encode(['description' => self::GOOD]), false, 0);

        // Customer permission middleware returns 401 for a missing module permission (as in WebsiteChoiceScreenTest).
        $this->suggest($workspace, $business, $page)->assertUnauthorized();
    }

    public function test_a_core_plan_without_the_seo_module_gets_no_suggestion(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $page = $this->homePage($this->createWebsite($business), ['title' => 'Photo Booth Rentals']);
        $this->authenticateAsCustomer($customer);
        $this->ai(json_encode(['description' => self::GOOD]), false, 0);

        $this->suggest($workspace, $business, $page)->assertNotFound();
    }

    public function test_an_exhausted_ai_budget_is_reported_and_writes_nothing(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $this->ai(null, true);

        $this->suggest($workspace, $business, $page)->assertStatus(429)->assertJson(['ok' => false, 'budget' => true]);

        $this->assertNull($page->fresh()->meta_description);
    }

    public function test_a_suggestion_with_an_invented_number_is_rejected(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $this->ai(json_encode(['description' => 'Photo booth rentals starting at $199 with a 100% satisfaction guarantee for every single event we cover.']));

        $this->suggest($workspace, $business, $page)->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_a_year_cannot_ground_an_unrelated_shorter_price(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $page->update(['title' => 'Photo Booth Rentals Since 1998']);
        $this->authenticateAsCustomer($customer);
        $this->ai(json_encode(['description' => 'Photo booth rentals from $19 for every celebration, with flexible event coverage and easy online booking for your date.']));

        $this->suggest($workspace, $business, $page)->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertNull($page->fresh()->meta_description);
    }

    public function test_another_businesss_page_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->growthTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $foreign = $this->homePage($this->createWebsite($otherBusiness));
        $this->authenticateAsCustomer($customer);
        $this->ai(json_encode(['description' => self::GOOD]), false, 0);

        $this->suggest($workspace, $business, $foreign)->assertNotFound();
    }

    private function save($workspace, $business, $page, array $data)
    {
        return $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $page->uid]), array_merge([
            'title' => $page->title, 'slug' => $page->slug ?? '', 'meta_description' => '',
        ], $data));
    }

    public function test_an_approved_suggestion_is_saved_exactly_to_the_draft_and_is_still_not_published(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $this->ai(json_encode(['description' => self::GOOD]));

        $proposed = $this->suggest($workspace, $business, $page)->assertOk()->json('description');
        $this->save($workspace, $business, $page, ['meta_description' => $proposed, 'draft_token' => $page->fresh()->draftToken()]);

        $this->assertSame(self::GOOD, $page->fresh()->meta_description);
        $this->assertNull($page->website->fresh()->published_revision_id, 'Saving is not publishing.');
    }

    public function test_a_stale_editor_never_overwrites_newer_draft_work(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $token = $page->fresh()->draftToken(); // a captured token before a different draft mutation

        DB::table('website_pages')->where('id', $page->id)->update(['meta_description' => 'Newer draft work']);

        $this->save($workspace, $business, $page, ['meta_description' => 'Stale proposal that must not win.', 'draft_token' => $token])
            ->assertSessionHas('status', 'error');

        $this->assertNotSame('Stale proposal that must not win.', $page->fresh()->meta_description);
    }

    public function test_two_edits_in_the_same_timestamp_second_cannot_overwrite_each_other(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $original = $page->fresh();
        $token = $original->draftToken();
        $unchangedTimestamp = $original->updated_at->format('Y-m-d H:i:s');

        // Simulate a concurrent edit without advancing the DB's second-resolution timestamp.
        DB::table('website_pages')->where('id', $page->id)->update([
            'meta_description' => 'More recent content from another editor.',
            'updated_at' => $unchangedTimestamp,
        ]);

        $this->save($workspace, $business, $page, [
            'meta_description' => 'Outdated content',
            'draft_token' => $token,
        ])->assertSessionHas('status', 'error');

        $this->assertSame('More recent content from another editor.', $page->fresh()->meta_description);
        $this->assertSame($unchangedTimestamp, $page->fresh()->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_another_businesss_page_cannot_be_changed(): void
    {
        [$customer, $business, $workspace] = $this->growthTenant();
        [, $otherBusiness, , $otherPage] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $otherPage->uid]), ['title' => 'X', 'meta_description' => 'Hijack'])
            ->assertNotFound();

        $this->assertNotSame('Hijack', $otherPage->fresh()->meta_description);
    }
}
