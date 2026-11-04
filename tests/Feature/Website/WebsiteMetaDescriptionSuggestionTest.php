<?php

namespace Tests\Feature\Website;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Website\WebsiteAiGenerationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->ai(json_encode(['description' => 'Photo booth rentals starting at $199 with a 100% satisfaction guarantee for every single event we cover.']), false, 2);

        $this->suggest($workspace, $business, $page)->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_a_year_cannot_ground_an_unrelated_shorter_price(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $page->update(['title' => 'Photo Booth Rentals Since 1998']);
        $this->authenticateAsCustomer($customer);
        $this->ai(json_encode(['description' => 'Photo booth rentals from $19 for every celebration, with flexible event coverage and easy online booking for your date.']), false, 2);

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

    /** @param list<?string> $outputs successive client results, one per attempt */
    private function aiSequence(array $outputs, array $budgetFlags = []): void
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturnUsing(function () use (&$budgetFlags) {
            return (bool) array_shift($budgetFlags);
        });
        $mock->shouldReceive('complete')->times(count($outputs))->andReturnValues($outputs);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rejections(): array
    {
        return [
            'invalid_json' => ['This is not json at all', 'invalid_json'],
            'missing_description' => [json_encode(['other' => 'x']), 'missing_description'],
            'too_short' => [json_encode(['description' => 'Short text.']), 'too_short'],
            'too_long' => [json_encode(['description' => str_repeat('Photo booth rentals for events. ', 8)]), 'too_long'],
            'unsupported_number' => [json_encode(['description' => 'Photo booth rentals from $199 for every celebration, with flexible event coverage and easy online booking for you.']), 'unsupported_number'],
        ];
    }

    /** @dataProvider rejections */
    public function test_each_rejection_logs_only_a_safe_reason_code_and_both_attempts_are_metered(string $output, string $reason): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $this->aiSequence([$output, $output]);

        $logged = [];
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = [$message, $context];
        });
        \Illuminate\Support\Facades\Log::shouldReceive('info', 'error', 'debug')->zeroOrMoreTimes();

        $this->suggest($workspace, $business, $page)->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertCount(2, $logged, 'One warning per rejected attempt, and no more than one retry.');
        $this->assertSame('motiongrove.website.meta_description_rejected', $logged[0][0]);
        $this->assertSame($reason, $logged[0][1]['reason']);
        $dump = json_encode($logged);
        $this->assertStringNotContainsString('Photo booth rentals', $dump, 'Generated text is never logged.');
        $this->assertStringNotContainsString('Photo Booth Rentals', $dump, 'Customer content is never logged.');
        $this->assertNull($page->fresh()->meta_description);
    }

    public function test_a_fenced_json_answer_is_accepted(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $this->aiSequence(["```json\n" . json_encode(['description' => self::GOOD]) . "\n```"]);

        $this->suggest($workspace, $business, $page)->assertOk()->assertJson(['ok' => true, 'description' => self::GOOD]);
    }

    public function test_a_failed_first_attempt_is_retried_once_and_the_retry_can_succeed(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $this->aiSequence(['not json', json_encode(['description' => self::GOOD])]);

        $this->suggest($workspace, $business, $page)->assertOk()->assertJson(['ok' => true, 'description' => self::GOOD]);

        $this->assertNull($page->fresh()->meta_description, 'Still review-only: nothing is saved.');
    }

    public function test_the_retry_is_still_number_checked(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $bad = json_encode(['description' => 'Photo booth rentals from $199 for every celebration, with flexible event coverage and easy online booking for you.']);
        $this->aiSequence(['not json', $bad]);

        $this->suggest($workspace, $business, $page)->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_a_budget_refusal_on_the_retry_stops_with_the_budget_message(): void
    {
        [$customer, $business, $workspace, $page] = $this->growthTenant();
        $this->authenticateAsCustomer($customer);
        $this->aiSequence(['not json', null], [false, true]);

        $this->suggest($workspace, $business, $page)->assertStatus(429)->assertJson(['ok' => false, 'budget' => true]);
        $this->assertNull($page->fresh()->meta_description);
    }
}
